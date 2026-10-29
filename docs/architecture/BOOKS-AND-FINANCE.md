# Books and finance

Audience: engineers working on money, and reviewers checking how amounts and
VAT figures are derived.

There are two money areas, and they never share tables:

| | Platform finance | Books |
|---|---|---|
| Whose money | The operator's: what customers pay the platform | A tenant's: what its own customers and suppliers owe |
| Domain | `app/Domains/Billing` | `app/Domains/Books` |
| Screens | Admin › Finance | Portal › Books (`BooksAccess` middleware) |
| Sales documents | Issued from the **billing organization** through its country's module, like any seller's (`PlatformInvoices`) | The tenant's own issued invoices and notes (read, never changed) |
| Purchases | `Expense`, `SupplierPayment`, `SupplierCreditNote` | `PurchaseInvoice`, `PurchaseLine`, `PurchasePayment` |

Books access is the `books` feature (`Platform\Access`) plus, for a change, a
role that keeps the books (`MemberRole::BOOKKEEPING`): an accountant records
here without being able to issue invoices; a viewer only reads.

## 1. Records are reversed, never edited

Every money record is append-only in effect:

- A customer or supplier payment recorded in error is answered by a
  **reversal row** below zero (`reverses_id`), never edited or deleted —
  `CustomerPayments::reverse`, `PurchasePayments::reverse`,
  `AccountCredits::reverse`, the admin payables' reverse action. A platform
  customer payment reversed (`Payments::reverse`) is marked `reversed_at`;
  its reports read it the same way: received in its own month, taken back
  in the month of the reversal (`FinanceReports::payments`, its fee in the
  P&L).
- A purchase or expense entered by mistake is **voided** with a reason
  (`Purchases::void`), never deleted; void documents never count.
- An issued invoice is never changed. A refund against it is a **credit
  note** issued through the module (`Refunds`; ZATCA E-Invoicing Detailed
  Guidelines: corrected by a note, never cancelled).
- A `PaymentAllocation` (part of a payment set against one invoice) is never
  changed; a mistake is answered with a reversal row, a refund or a credit.
  Every reversal column (`reverses_id`) is unique, so a row is reversed at
  most once.
- The same supplier's same document number is refused twice while the first
  is not void. This is a check when the purchase is recorded
  (`Purchases::figures`), not a unique index: a void purchase keeps its
  number, and the same number may then be recorded again.

Rows that are locked while checked: the invoice when a payment is set against
it, the purchase when a supplier payment is, and the invoice when a refund is
reserved (`RefundIntent`), so two clicks cannot take the same money twice.

## 2. As-at reporting

`Books\Services\Receivables::asAt()` rebuilds each issued invoice's balance
from what had happened **by the date asked**: the invoice by issue date, its
credit notes by theirs, payments and their reversals by payment date. A
month-end ageing run weeks later gives the same answer as one run on the day.
Drafts, proformas and documents the authority refused owe nothing.

- **Credit notes on several invoices** (`BillingReferences`) are spread by
  `Receivables::spread()`: each original's share of what they were all owed,
  in the order named, the last taking the rounding. Bad-debt relief
  (`SalesBadDebts::claim`) is on the balance after that, so the note's VAT is
  never relieved again; the payment cap and ageing read the same balance.
- **One invoice is read alone** (`Receivables::balanceOf`): its payments and
  reliefs, and the credit notes naming it (exact reference, or a list holding
  it, parsed), plus the originals those notes name for the spread - a fixed
  handful of queries whatever the organization's volume. `outstanding()`, the
  payment cap and relief use it. The list of what is still owed (`open`)
  leaves till-settled simplified sales, and the notes on them, out in SQL;
  payments and reliefs are summed in the query. One customer's documents use
  `invoices_org_customer_idx`. `ReceivablesTest::test_one_balance_reads_bounded_queries`
  holds the query count constant from 3 to 30 other invoices.
- **Refunds and money on account** (`CustomerPayments`). A refund of a credit
  a document holds is an `invoice_payments` row below zero that reverses
  nothing. Money paid before an invoice is a `customer_receipts` row;
  setting it against an invoice records that invoice's payment naming it
  (`receipt_id`), and a refund or reversal of it is a row naming it. What is
  left on it is a credit row of its own in `asAt()`; the statement counts the
  money when it came and leaves its allocations out.
- **A reversed payment undoes what it set off** (`CustomerPayments::reverse`):
  a voucher redemption gets a row below zero; a bad-debt recovery made by the
  payment (`bad_debt_reliefs.invoice_payment_id`) gets a `recovery_reversed`
  row in the reversal's period (`SalesBadDebts::undoRecovery`), and its
  drafted Art 40(9) tax invoice is discarded. If that invoice was issued, the
  row is `by_credit_note`: the person is told to credit the issued invoice,
  the credit note carries the VAT, and the return leaves the row out.
  Receivables and statements leave a note on a recovery invoice out.

Platform statements (`Billing\Services\Statements`) are a ledger per currency
with the balance after each line and an ageing in 0 / 1–30 / 31–60 / 61–90 /
90+ day buckets. Finance reports take a period of days, both ends counted.

## 3. Currency and exchange rates

- A document in another currency records the **rate it was issued at**
  (`invoices.exchange_rate`), and its VAT is also stated in the tax currency at
  that rate — ZATCA requires the tax currency (BT-6, BR-KSA-68) to be SAR
  (BR-KSA-EN16931-02) and the total VAT in it (BT-111).
- `ExchangeRates` holds one rate per currency per day to the billing
  organization's tax currency; where it is the source, the rate is the latest
  on or before the document's date, and the receivable records which day.
- Purchase and expense lines are kept in the currency billed and in the tax
  currency at the document's rate, rounded per line (KSA VAT Implementing
  Regulations Art 61: converted at the rate of the day).
- Platform finance only: a payment in another currency keeps the difference
  against each invoice's own rate on the allocation. Books payments (customer
  and supplier) are in the document's own currency and record no rate or
  exchange difference.
- Sales reports convert each document at its own rate; a document with no
  rate is left out and named.

## 4. The VAT return draft

Each country module implements the engine's `VatReturn` port
(`Compliance\Engine\Contracts\VatReturn`): every box of the country's form, in
its tax currency, each traceable to the documents behind it
(`VatReturnTally`). Sales come from issued documents (`VatSummary`),
purchases from Books (`PurchaseLedger`). **It is a draft: the platform files no
return.** What the books cannot know is typed in (`manualEntries()`).

Beside the form, `VatReturnDraft::$reconciliation` holds figures that tie it
to another record and are never part of it: `VatPeriods::figures()` snapshots
the sections only, so a later answer is no drift. The Saudi return splits its
sales boxes by where each document stands with ZATCA (`FilingStatus`: cleared
or reported, not answered yet, held), from the same rounded entries, so the
total equals box 6; rejected documents, which no box counts, are listed apart.
The sales report shows the same split for the branches it reads.

| | Saudi Arabia | UAE (VAT 201) |
|---|---|---|
| Boxes | 1–5 sales by category, 6 total; 7 domestic standard purchases, 8 imports VAT paid at customs, 9 reverse charge (services from abroad and imports accounted for in the return, `import_in_return`), 10 zero, 11 exempt, 12 total; 13 VAT due, 14 corrections (±SAR 5,000), 15 credit carried forward, 16 net | 1a–1g standard supplies per emirate, 2 tourist refunds (typed in), 3 reverse charge, 4 zero, 5 exempt, 6 imports accounted for in the return (`import_in_return`, CD 52 Art 48(1)), 7 import adjustments, 8 totals; 9 standard expenses, including import VAT paid at customs (`import`, CD 52 Art 50(1); Decree-Law Art 54), 10 reverse-charge recovery, 11 totals; 12 due, 13 recoverable, 14 payable |
| Source of the box list | KSA VAT Implementing Regulations Art 59(2) | Cabinet Decision No. 52 of 2017 Art 64(5) |
| Input tax timing (`inputTaxOnPayment()`) | `false` — deducted on holding the tax invoice, paid or not (IR Art 49(7)), in the period of supply or later (Art 49(8)) | `true` — see below |
| Reverse charge | Due and deductible in the same return (IR Art 47(1)); added to box 13 | Box 3 due, box 10 recoverable (Decree-Law Art 48(1)) |
| Non-deductible share | Taken off in the box's adjustment (IR Art 49–51) | Recoverable share only (Decree-Law Art 54–55; CD 52 Art 53, 55) |
| Earlier-period credit notes, sales | A note is reported in its own period, never the original's (IR Art 40(4)–(5), `VatPeriodTracker`); against an earlier period it goes in Adjustments (IR Art 54; Tax Invoicing Guideline §7.1.1). The original is matched by number or row id; a note on several invoices adjusts when any of them, so its earliest, is from an earlier period (`VatSummary`, `BillingReferences`) | Netted in the box's amount (`UaeVatSummary`) |
| Earlier-period credit notes, purchases | Adjustments | Box 9 adjustment; imports in box 7 |
| Bad-debt relief (`SalesBadDebts`) | Box 1 adjustment; 12 months from supply, accountant's certificate, customer not related, above SAR 100,000 evidence of failed recovery; tax again on a later payment (IR Art 40(7)–(9)) | Box 1a–1g adjustment; more than 6 months from supply, written off, customer notified (Decree-Law Art 64(1)); tax again on a later payment |
| Domestic reverse charge | None (reverse charge is for supplies from abroad, IR Art 47(1)) | Specified goods from a registered UAE supplier to a buyer with a TRN: box 3 due, box 10 recoverable (Decree-Law Art 48(3), (8)) |

<!-- app/Domains/Compliance/Countries/SaudiArabia/Reports/VatReturn.php;
     app/Domains/Compliance/Countries/UnitedArabEmirates/Reports/VatReturn.php -->

### The UAE input-tax-on-payment rule

Federal Decree-Law No. 8 of 2017 Art 55(1): recoverable input tax is deducted
in the return of the first tax period in which the person (a) has received and
keeps the tax invoice (or import documents) **and** (b) has paid the
consideration, or part of it. Cabinet Decision No. 52 of 2017 Art 54(2) treats
it as paid to the extent the person intends to pay within six months of the
agreed payment date; Art 54(1) recovers, in a period, the input tax on the
portion paid in that period.

So `PurchaseLedger` (with `asPaid`) reports a purchase the organization will
not pay within six months of its agreed date (`paid_later`) when received
with nothing recovered, then recovers its input tax period by period, in
proportion to what was paid. What a period recovers is what the purchase is
owed by the period's end less what it was owed before: its input tax after
the credit notes received by then, times what was paid by then over what it
then costs. A supplier credit note after payment therefore takes back the
tax on what it credits, in the note's period; a payment can never exceed
what is left to pay after credit notes (`PurchasePayments::outstanding`). Where the supplier writes off the unpaid
consideration (`written_off_on`), input tax recovered on the unpaid part is
taken back in that period (Decree-Law Art 64(2), as the code cites it). A
purchase belongs to the period of the day it was received (`received_on`),
never before the document's date.

### Filed periods

`VatPeriods` records each period an owner, admin or accountant marks filed,
with the return's figures as filed. After filing:

- a purchase or supplier credit note received on a day in a filed period
  counts from the next open day (KSA IR Art 49(8); UAE Decree-Law Art 55(2));
- a supplier payment or customer payment dated in it is taken account of from
  the next open day (UAE recovery on payment, bad-debt recovery);
- voiding a purchase dated in it, a supplier's write-off or a bad-debt claim
  dated in it is refused: correct it with a credit note, or in the next
  return;
- a sale whose tax point falls in it after filing (issuing does not stop it)
  shows on the period's page as the difference from what was filed;
- recording a deemed supply dated in it is refused (`DeemedSupplies::record`).

Filing checks the period's shape - it ends on a month's last day and runs one
month or three (KSA IR Art 58; UAE Decree-Law Art 74) - and that it starts the
day after the last filed period, so none is skipped; the first may start
mid-month. The snapshot keeps the reconciliation section beside the boxes.
The return page also lists taxed deemed supplies whose kept invoice is a
draft and contract retention held past its tax point; how each is boxed waits
on the adviser (Q7, Q8).

Only an owner reopens a filed period, with a reason, on the audit log.

## 5. Vouchers and deemed supplies

What each country's law says of them comes from the engine's
`SpecialSupplies` port ([JURISDICTION-ROUTING.md](JURISDICTION-ROUTING.md)).

- **Vouchers** (`Books\Services\Vouchers`). Selling one at its face value is
  not a supply (`SpecialSupplies::vouchers()`; KSA IR Art 19(1), UAE
  Decree-Law Art 7(1)): no tax invoice and no VAT. Using it pays an issued
  invoice, which bears the VAT, recorded as that invoice's customer payment
  by voucher. Its balance is the face value less its redemptions; nothing on
  the voucher changes after it is sold.
- **Deemed supplies** (`Books\Services\DeemedSupplies`). Goods or services
  taken for non-business use, given away or kept on deregistration are
  recorded with their value and the share of input tax deducted, and decided
  by `SpecialSupplies::deemedSupplies()`: not deemed with no input tax
  deducted, relieved within the country's thresholds, otherwise taxed. A
  taxed one drafts a kept invoice stating its output tax, given to no one;
  once issued its VAT counts with the return's standard-rated sales.

## 6. Platform finance in brief

- **Receivables** — invoices to customers in the customer's currency, for the
  amount agreed (no price list); nothing invoiced inside a free period
  (`FreePeriod`); statuses `open`, `partly_paid`, `paid`, `overdue`,
  `written_off`. One credited in full was settled by its note, not paid: it
  does not count in days to pay (`FinanceReports::collections`).
- **Payments** — bank transfer recorded by an admin, or card through the
  gateway port (`PaymentGateway`); allocated to named or oldest open invoices;
  the remainder becomes the customer's credit; the fee kept by the bank or
  gateway is recorded on the payment.
- **Reconciliation** — bank statements imported as CAMT.053, MT940 or CSV and
  matched against payments by channel and reference; card settlement reports
  through the `SettlementReport` port.
- **Expenses and payables** — the platform's own costs, with a VAT treatment
  (`recoverable`, `not_recoverable`, `no_vat`, `reverse_charge`) for its own
  return.
- **VAT draft** — the sales side comes from the billing company's own
  country's `VatReturn` (by box and tax point), the same return Books drafts;
  the purchase side from expenses. The platform's documents are selected by
  status (issued, not refused), not by the ZATCA hash, so a billing company in
  either country reports its sales. The finance VAT report
  (`FinanceReports::vat`) lists the same documents by tax point
  (`VatSummary::issued`), every branch; it reads them across tenants by the
  billing company's id, so the admin screen, which has no tenant, sees them.
- **Write-offs and relief** - a write-off is split in the P&L into what it was
  before VAT (`bad_debts`) and its VAT (`bad_debt_vat`), the VAT's share being
  the invoice's own. Relief on the billing company's own invoices goes through
  the same `SalesBadDebts` rules as any organization's; its VAT shows as
  `vat_relief` in the P&L and as a line of its own in the VAT view, with the
  VAT on the period's write-offs beside it.
- **Journal, trial balance, cash** - `ExpenseReports::journal()` posts every
  figure of the P&L and the VAT view as balanced double entries in the tax
  currency, against receivables, payables, VAT input and output and each bank
  account; `trialBalance()` sums it by account (run from the first day for
  balances as at a date); `cash()` is money in and out by bank account in its
  own currency. CSVs under *billing.manage*. A test ties each month's P&L
  revenue to the VAT draft's sales and to receivables issued.
- **Revenue deferral** - `ExpenseReports::deferral()` spreads each invoice's
  amount before VAT over the days of the period it covers and shows what is
  recognised and still deferred by month. It is a report only: the P&L stays
  on the tax point until the owner decides the accounting policy, including
  how credit notes and write-offs on a part-recognised period are spread.
- **Freeze** — the overdue-payment freeze ships off. When on, it never stops
  a customer reading or exporting its records, filing what it already issued,
  or correcting an issued invoice with a note (`Standing::FEATURES_KEPT`).

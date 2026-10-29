# Books

Audience: accountants, owners and anyone who closes the month.

**Books** holds what you need to close the month: purchases, input VAT, the
VAT return drafted from your sales and purchases, what customers owe and paid,
and sales reports.

## Who can use it

| Role | In Books |
|---|---|
| Owner, admin, member, accountant | Read and record |
| Viewer | Read only |

The accountant records in Books and changes nothing else in the portal.
A platform administrator can switch Books off for a company. Books stays open
while a company's account is restricted for an overdue payment, and while
e-invoicing in its country is switched off.

<!-- source: app/Domains/Books/Http/Middleware/BooksAccess.php; app/Domains/Organization/Enums/MemberRole.php (BOOKKEEPING); app/Domains/Platform/Access/Feature.php; app/Domains/Platform/Access/Standing.php -->

## Suppliers

**Suppliers** keeps each supplier's name (English and Arabic), VAT number,
country, city, emirate or region, email and phone. **Remove supplier** takes it
off the list. Purchases already recorded keep it.

## Purchases

**Record a purchase** with:

- the supplier, the document (invoice or credit note), its number and date;
- **Received on** - the purchase counts in the VAT period of this date;
- the agreed payment date, the currency and, for a foreign currency, the exchange rate;
- **Deductible share of the VAT (%)** - 100 by default;
- up to five lines, each with a VAT treatment, amount before VAT, VAT, rate
  and whether it is a capital asset;
- the supplier's document as a PDF or image (up to 10 MB).

VAT treatments: Standard rated, Zero rated, Exempt, Out of scope, Import (VAT
paid at customs), Import (VAT accounted for in the return) and Reverse charge
(services from abroad). Leave VAT empty and it is worked out from the rate.

Choose the import treatment by how the VAT was settled. **Paid at customs**:
the customs declaration shows the VAT you paid before release; you recover it
(Saudi box 8; UAE box 9 with your other expenses), and nothing more is due.
**Accounted for in the return**: goods cleared under your own customs code
(UAE) or ZATCA's deferral (Saudi); the VAT is due and recovered in the same
return (UAE box 6 and box 10; Saudi box 9).

- Reverse charge is only for a supplier outside your country: give the
  supplier its country.
- In the UAE, Import is the VAT you account for yourself on the return (box 6),
  and recover in box 10.
- In the UAE, a business with a TRN also has **Domestic reverse charge
  (specified goods)**: goods such as oil and gas, or goods the Cabinet
  specifies, bought from a registered UAE supplier. Choose which goods on the
  line. You declare the VAT (box 3) and recover it (box 10).
  **To verify against the Cabinet decisions:** the choices are named by the
  law's clause and code only (for example *Goods the Cabinet specifies, Art
  48(8) - DL8.48.8.1*), because which goods each code covers is set by
  Cabinet decisions that are not built in yet. Choose the code your
  supplier's e-invoice states, and check it with your tax adviser.
- A purchase dated in a VAT period you have already filed counts in the next
  open period.

A purchase is **Recorded** or **Void**. **Void purchase** is permanent and needs
a reason. It is refused while a credit note answers the invoice (void the note
first), or when the purchase is in a filed VAT period (record a credit note
instead). Record payments to the supplier on the purchase. A payment can be
reversed, and the reversal stays on record.

A supplier credit note names the invoice it corrects. With the credit notes
already recorded against that invoice it cannot take back more than the
invoice charged, in total or in VAT: the form says how much is left to credit.

**Import purchases** from CSV: download the template, fill it, upload. Every
row is checked first. If any row is wrong, nothing is recorded and each problem
is listed. Unknown suppliers are created.

<!-- source: resources/views/portal/books/purchase-form.blade.php; app/Domains/Books/Enums/PurchaseTreatment.php; app/Domains/Books/Services/Purchases.php; app/Domains/Books/Services/PurchaseImport.php -->

## Input VAT

### Saudi Arabia

Input VAT is deducted when you hold the supplier's tax invoice, paid or not.

**Unpaid 12 months after the supply.** If a supplier invoice is still not paid
in full 12 months after its date, the input VAT on the unpaid part is given
back in the return of the period that day falls in (VAT Implementing
Regulations Art 40(10)). It shows in box 7 as a negative adjustment. When you
later pay, record the payment: the input VAT on what you paid is reclaimed in
the period of the payment (Art 40(11)). If that period is already filed, it
goes in the next open one.

Example: 10,000 + VAT 1,500 = 11,500, 4,600 paid. Twelve months later 6,900
is unpaid, so 1,500 x 6,900 / 11,500 = 900.00 comes off box 7. A later
payment of 2,300 reclaims 300.00.

Only standard-rated domestic purchases count: VAT on imports and the reverse
charge is not part of what the supplier is owed. **Purchases > Purchases
unpaid over 12 months** lists them on any date, and the VAT return names them.

<!-- source: app/Domains/Compliance/Countries/SaudiArabia/Reports/VatReturn.php inputTaxOnPayment(), unpaidInputTaxMonths(); app/Domains/Books/Services/PurchaseLedger.php unpaidAdjustments(), unpaidOver() -->

### UAE: pay or intend to pay

UAE rules let you recover input VAT when you have paid the supplier, or intend
to pay within six months of the agreed payment date.

- By default the full input VAT is recovered when the purchase is received.
- If you will **not** pay within six months, tick **Will not be paid within
  six months of the agreed payment date**. Its input VAT is then recovered only
  as you pay, in the period of each payment.
- The VAT return warns you about purchases recovered on the intent to pay and
  still unpaid six months after the agreed date.
- If the supplier writes the debt off and tells you, use **Record write-off**
  on the purchase. That takes the input VAT back. It cannot be undone.

Nothing is reversed automatically. You decide, and the return reminds you.

<!-- source: app/Domains/Compliance/Countries/UnitedArabEmirates/Reports/VatReturn.php; app/Domains/Books/Services/PurchaseLedger.php overdue(), recoveries() -->

## VAT return, deemed supplies and vouchers

The VAT return draft, filing status with ZATCA, marking a period filed,
deemed supplies and vouchers are in [VAT-RETURN.md](VAT-RETURN.md).

## Receivables and statements

**Receivables** shows what each customer owes on any date (**As at**), by age:
not yet due, 1-30, 31-60, 61-90 and over 90 days. Drafts and refused
documents are left out. Credit notes reduce the invoice they correct. A credit
note on several invoices is spread over them by each one's share of what they
were all owed (the last takes any halala left), so each invoice shows its part
and none of the note is counted twice. Money a customer paid on account shows
as a credit of its own (**On account**), and links to the customer's statement.

A simplified invoice paid in cash or by card at the till counts as paid on
issue, so it never shows as owed.

Open a sale to **Record a payment**: amount, date, method (bank transfer, cash,
card, cheque, other) and reference. A payment cannot be more than what is owed.
A reversed payment stays on record with its reason. Reversing it also takes
back what it set off: a payment by voucher gives the voucher its value back,
and a payment after bad-debt relief is written off again (see below).

When an invoice holds a credit (paid, then credited) or a credit note names no
invoice, **Pay back the credit** records a refund: amount, date, method and
reference, up to the credit. The statement shows it as **Refund**.

**Claim bad-debt relief** on a sale the customer never paid takes the output
VAT on the unpaid part off the return, once the conditions are met. The unpaid
part is what is left after every credit note on it, a note on several invoices
by its share:

- Saudi Arabia: at least 12 months since the supply, the period of the sale
  filed, a certified accountant's certificate of the write-off, a customer who
  is not a related person, and, where the customer owes more than SAR 100,000,
  evidence that formal recovery failed (VAT Implementing Regulations Art 40).
  What the customer owes is weighed in riyals, each invoice at its rate; one
  in another currency with no rate needs the evidence.
- UAE: more than 6 months since the supply, the period of the sale filed,
  written off in your books, and the customer told (Decree-Law Art 64).

If the customer pays later, record the payment as usual: the VAT on it is due
again in that period, in proportion. Example: 11,500 written off, 2,300 paid
later - a fifth - so 300 of the 1,500 VAT is due again.

In Saudi Arabia a new tax invoice must also be issued for the amount received
(VAT Implementing Regulations Art 40(9)). Recording the payment drafts it for
you: the same buyer and lines, each at the share received, with its VAT
(2,000 + 300 = 2,300 above), noting the invoice and the article. Open it from
the sale's **Bad-debt relief** card, check it and issue it. Its VAT is
already in the return through the payment, so it is not counted again, and
it is not owed again. If it cannot be drafted, the card says so: write it on
the new-invoice form.

If that payment is reversed (a cheque bounced), what it gave back is written
off again and its VAT taken back again in the period of the reversal. A
drafted tax invoice for it is discarded. One already issued stays on the
authority's record: the card says to issue a credit note against it, and that
credit note takes its VAT back in the return, not the reversal as well.

**Statement of account** for a customer shows the opening balance, what was
billed, paid and credited, and the closing balance for a period.
**Download PDF** to send it.

**Money on account** on the statement holds money a customer paid before an
invoice. **Record money received on account** (amount, currency, date, method,
reference). Each receipt shows what is left: **Pay invoice** sets part of it
against one of the customer's open invoices in the same currency, and **Record
refund** pays back what is left. The statement counts the money once, when it
came. A receipt is reversed only once nothing is paid from it or refunded;
reverse those first. Books has no API for payments, so these are recorded on
the page.

Receivables, a sale's payments and statements show the branches you see,
like every list of invoices: someone limited to some branches sees only
theirs, and the branch switcher narrows them further. A statement drawn that
way says it is not the customer's whole account.

<!-- source: app/Domains/Books/Services/Receivables.php; app/Domains/Books/Services/CustomerStatements.php; app/Domains/Books/Services/CustomerPayments.php refund(), receive(), allocate(), refundReceipt(); resources/views/portal/books/invoice.blade.php; resources/views/portal/books/statement.blade.php; app/Domains/Books/Services/SalesBadDebts.php recover(), undoRecovery(); app/Domains/Invoice/Services/InvoiceAdjustments.php recoveryInvoice() -->

## Sales reports

**Sales reports** groups sales by customer, item, branch, VAT category or
month, with documents, quantity, net, VAT and total. Credit notes are taken
off. Export as CSV.

## Month-end close

The steps are in [WORKFLOWS.md](WORKFLOWS.md#month-end-close-in-books).

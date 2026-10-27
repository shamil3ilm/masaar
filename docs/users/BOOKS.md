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

A purchase is **Recorded** or **Void**. **Void purchase** is permanent and needs
a reason. Record payments to the supplier on the purchase. A payment can be
reversed, and the reversal stays on record.

**Import purchases** from CSV: download the template, fill it, upload. Every
row is checked first. If any row is wrong, nothing is recorded and each problem
is listed. Unknown suppliers are created.

<!-- source: resources/views/portal/books/purchase-form.blade.php; app/Domains/Books/Enums/PurchaseTreatment.php; app/Domains/Books/Services/Purchases.php; app/Domains/Books/Services/PurchaseImport.php -->

## Input VAT

### Saudi Arabia

Input VAT is deducted when you hold the supplier's tax invoice, paid or not.

<!-- source: app/Domains/Compliance/Countries/SaudiArabia/Reports/VatReturn.php inputTaxOnPayment() -->

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

## VAT return

**VAT return** drafts the return box by box from your issued invoices and the
purchases in Books. Choose the period (**From**, **To**; up to 400 days) and
type in the figures only you know, such as corrections or tax carried forward.
Then **Draft the return**.

- Saudi Arabia: ZATCA's return, boxes 1 to 16, in SAR.
- UAE: VAT 201, boxes 1 to 14, in AED, with standard-rated sales split by emirate.
  A sale with no emirate is flagged: give each branch its emirate.

Click a figure to see every document behind it. **Download CSV** or
**Download PDF**.

It is a draft. Nothing is saved or filed. Check it, then file it yourself with
the authority.

The return is the whole organization's: one return per period for the
taxable person (KSA VAT Implementing Regulations Art 59; UAE VAT
Executive Regulation, Cabinet Decision 52 of 2017, Art 64). It always covers every branch, for sales and
purchases alike, whatever the branch switcher shows. Only someone who sees
every branch (an owner, an administrator, the accountant, or a member not
limited to branches) can open it; anyone limited to some branches is sent
back to Books with that reason. For figures per branch, use **Sales reports**
grouped by branch; they are not a return.

<!-- source: resources/views/portal/books/vat-return.blade.php; app/Domains/Books/Http/Concerns/ReadsBooksPeriod.php; app/Domains/Books/Http/Controllers/PortalVatReturnController.php -->

## Receivables and statements

**Receivables** shows what each customer owes on any date (**As at**), by age:
not yet due, 1-30, 31-60, 61-90 and over 90 days. Drafts and refused
documents are left out. Credit notes reduce the invoice they correct.

Open a sale to **Record a payment**: amount, date, method (bank transfer, cash,
card, cheque, other) and reference. A payment cannot be more than what is owed.
A reversed payment stays on record with its reason.

**Statement of account** for a customer shows the opening balance, what was
billed, paid and credited, and the closing balance for a period.
**Download PDF** to send it.

Receivables, a sale's payments and statements show the branches you see,
like every list of invoices: someone limited to some branches sees only
theirs, and the branch switcher narrows them further. A statement drawn that
way says it is not the customer's whole account.

<!-- source: app/Domains/Books/Services/Receivables.php; app/Domains/Books/Services/CustomerStatements.php; resources/views/portal/books/invoice.blade.php -->

## Sales reports

**Sales reports** groups sales by customer, item, branch, VAT category or
month, with documents, quantity, net, VAT and total. Credit notes are taken
off. Export as CSV.

## Month-end close

The steps are in [WORKFLOWS.md](WORKFLOWS.md#month-end-close-in-books).

# Returns, summary invoices, contracts and disbursements

Audience: owners, admins and members who issue invoices.

Documents made from an issued invoice or from work done over time: returns and
exchanges, a month's deliveries on one invoice, contracts billed in stages,
and costs passed on for a customer. Issuing and notes are in
[INVOICING.md](INVOICING.md).

## Returns, exchanges, replacements and corrections

On an issued invoice, **Return, exchange or replace** asks what happened and
makes the documents for it. You see them, with their totals, before anything
is issued (**Preview the documents**, then **Make the documents**). The invoice itself
never changes.

| What happened | What is made |
|---|---|
| **Full return** | A credit note for the whole invoice |
| **Partial return** (lines and quantities) | A credit note for what came back |
| **Damaged, kept at a discount** (an amount before VAT, or a %) | A credit note for the discount, on the lines you tick |
| **Exchange for other items** | A credit note for what came back, and a new **draft** invoice for what the customer takes |
| **Replaced by an item at another price** | A debit note for the difference if the new item costs more (in the UAE, a new tax invoice), a credit note if it costs less |
| **Replaced free by the same item** | No tax document: an entry on the invoice with your reason and the quantities |
| **Buyer details corrected** | A credit note for the whole invoice, and a new **draft** invoice to the right buyer, its lines as they were |

Example: 4 chairs at 250 and a desk at 2,000, VAT 15%. Two chairs back: a
credit note for 500 + 75 = 575.00. One chair swapped for one at 400: a debit
note for 150 + 22.50 = 172.50.

- Each note names the invoice and states the official reason, in English and
  Arabic: a return is *goods or services refund*, a discount *amendment of
  the supply value*, a different item *essential change in the supply*, a
  buyer correction *change in the buyer's information*. In the UAE the tax
  credit note carries the matching code (`DL8.61.1.D`, `C`, `B`, `E`).
- A note is the same type as the invoice: simplified or standard.
- Saudi Arabia: the invoice must be **accepted** by ZATCA first, and credit
  notes can never come to more than the invoice and its debit notes.
- What was returned once cannot be returned again.
- The notes are dated today, so they count in the current VAT period. A
  credit note against an invoice of an earlier period shows as an adjustment;
  a debit note always adds to the period's amount. A credit note on several
  invoices shows as an adjustment when any of them is from an earlier period.
- If your company holds notes for approval, the note waits under
  **Approvals**; the new draft invoice is made at once.
- A new invoice is left as a draft: check it and issue it.
- Who can: anyone who may issue invoices at the invoice's branch. Viewers
  cannot.

Everything done shows under **After issue** on the invoice, with links to the
documents and where each stands.

<!-- source: app/Domains/Invoice/Services/InvoiceAdjustments.php; app/Domains/Compliance/Countries/SaudiArabia/Rules/Adjustments.php; app/Domains/Compliance/Countries/UnitedArabEmirates/Rules/Adjustments.php; resources/views/portal/invoices/adjustments/create.blade.php -->

## Summary invoices

**Summary invoices** keeps what you deliver as **delivery notes** and puts a
customer's month on one summary tax invoice. A delivery note is not a tax
document: nothing is filed for it and no VAT is due on it until the invoice.

**Summary invoices** and **Contracts** are in the menu unless your business
type sells over the counter (its invoices start simplified: a shop, a
pharmacy, a school). Once you have a delivery note or a contract, the link
stays whatever your type.

1. Record each delivery: the customer, the date, the lines at prices before
   VAT. A number is given if you leave it empty.
2. Pick the customer and the month, then **Draft the summary invoice**. Every
   delivery note of theirs in that month not yet invoiced goes on one draft,
   each line naming its delivery and date, the month as the supply period.
3. Check the draft and issue it as any invoice.

Example: 10 bags of cement at 20.00 on 3 October and 5 steel bars at 100.00
on 17 October make one invoice of 700.00 + VAT 15% 105.00 = 805.00 for
1-31 October.

- A delivery is invoiced once. Delete the draft and its delivery notes are
  free again; a delivery note on an invoice cannot be removed.
- A summary covers one calendar month at most and is drafted after the month
  ends. Issue it by the **15th of the next month** in Saudi Arabia (VAT
  Implementing Regulations Art 53(4)), within **14 days of the month's end**
  in the UAE (Executive Regulation Art 59(13)(b)).
- Saudi Arabia: a summary is a standard tax invoice (KSA-2 position 6), so the
  customer needs a VAT number or other ID and a national address. A summary
  simplified invoice is only for the suppliers Art 53(7) names. UAE: a tax
  invoice with the summary flag (`00010000`) and the period (IBR-138-AE).

<!-- source: app/Domains/Invoice/Services/SummaryInvoices.php; app/Domains/Compliance/Countries/SaudiArabia/Rules/SpecialSupplies.php; app/Domains/Compliance/Countries/UnitedArabEmirates/Rules/SpecialSupplies.php -->

## Contracts billed in stages, with retention

**Contracts** bills construction and other contracts as the work progresses.
Record the contract - customer, reference, value before VAT, retention % -
and its stages, each an amount or a percentage of the value. Then:

- **Invoice this stage** drafts a tax invoice for the stage's value less the
  retention withheld from it. VAT is charged on what the invoice states. The
  invoice names the contract (its reference) and says nothing of retention:
  the calculation is on the contract's statement.
- **Release retention** drafts a tax invoice for what is held (or a part),
  when the customer becomes liable to pay it. VAT on the retention is charged
  then.
- The **statement** shows the contract value, what was billed, retained,
  released, what is still held and what is left to bill.

Example: a villa for 1,000,000 with 10% retention, foundations 20%. Its
invoice is 200,000 less 20,000 = 180,000 + VAT 15% 27,000 = 207,000.
Releasing the 20,000 later is an invoice of 20,000 + 3,000.

- A stage is invoiced once; delete its draft to invoice it again.
- Retention cannot wait for ever: if it is neither invoiced nor paid within
  **12 months** of the first stage it was withheld from (Saudi Arabia, VAT
  Implementing Regulations Art 20(3)) or **one year** (UAE, Decree-Law
  Art 26(1)(d)), the VAT on it falls due then. The statement shows the date.
- UAE: stage and release invoices carry the continuous-supply flag
  (`00001000`), and the retention calculation stays off the e-invoice, as the
  UAE Electronic Invoicing Guidelines (scenario 5, Appendix 5) require.
- A contract is not a schedule: a schedule bills by the calendar, a stage
  when the work reaches it.

<!-- source: app/Domains/Invoice/Services/ProgressBilling.php; app/Domains/Compliance/Countries/SaudiArabia/Rules/SpecialSupplies.php; app/Domains/Compliance/Countries/UnitedArabEmirates/Rules/SpecialSupplies.php -->

## Disbursements

A **disbursement** is a cost you paid in your customer's name and for their
account - a court fee, a government fee, a visa - passed on at exactly what
you paid. In Saudi Arabia it is outside the value of your supply (GCC VAT
Agreement Art 26(6)(c)), so it carries no VAT.

On a line, set **Line type** to **Disbursement**, then tick that your
disbursement lines meet the conditions:

- it was paid in the customer's name;
- for the customer's account, and charged at exactly what was paid;
- you do not deduct the input VAT on it (record its cost in Books as not
  recoverable).

The line is then out of scope (`O`, reason code `VATEX-SA-OOS`, with the
reason in English and Arabic) whatever VAT it was given. The printed invoice
shows what the disbursements come to apart from the supply, and the VAT
report shows them under out of scope.

Example: legal services of 1,000.00 and a court fee of 500.00 paid in the
client's name: 1,000.00 + VAT 15% 150.00, and 500.00 out of scope, 1,650.00
in all.

UAE: not offered. The UAE texts at hand set no conditions for a
disbursement, so a line marked as one is refused.

<!-- source: app/Domains/Invoice/Support/DisbursementLines.php; app/Domains/Compliance/Countries/SaudiArabia/Rules/SpecialSupplies.php -->

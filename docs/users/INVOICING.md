# Invoicing

Audience: owners, admins and members who issue invoices, and anyone who reads them.

How to issue documents, what each status means, and how to print and find
them. Owners, admins and members issue. Accountants and viewers read only.

<!-- source: app/Domains/Organization/Enums/MemberRole.php; routes/web.php (org.writer group) -->

## Issue an invoice

1. **Invoices > New invoice**.
2. Choose the **Type**:
   - **Simplified (B2C)** - to a consumer. The buyer may be left empty for a walk-in customer.
   - **Standard (B2B)** - to a business. Needs the buyer's VAT number (or
     another identifier) and address.
   - **Government buyer (B2G)** - needs the contract the sale falls under.
3. Choose the **Document**: Invoice, Credit note, Debit note or Prepayment.
4. Pick a **Saved customer** or fill in the buyer. Tick **Save this buyer to
   Customers** to keep it.
5. Add lines. Pick a product to fill in a line, or type it.
6. **Save as draft**, or issue it.

The invoice must be in Arabic: each line's description, notes, payment terms,
charge reasons, a note's reason and, on a standard invoice, the buyer's name.
English may go beside it. A saved customer or product fills in its Arabic.

<!-- source: resources/views/countries/saudi-arabia/invoice/create.blade.php -->

### Options on the form

- **Prices** - *Include VAT* (the customer pays exactly these) or *Exclude VAT*
  (VAT is added). Your company's default applies unless you change it.
- **Document discount** - spread across the tax categories, not taken off the total.
- **VAT treatment** per line - Standard-rated, Zero-rated, Exempt or Out of
  scope. For zero-rated or exempt lines, choose the **Reason for zero rating or
  exemption** from the official code list. Codes common for your business type
  are listed first. **Reason in words** is filled in from the official text.
- **Currency** - anything but SAR needs an **Exchange rate**. VAT is reported in SAR at that rate.
- **Supply date** and **Supply end date** - for a supply over a period, such as a subscription.
- **Transaction type** flags - Third party, Nominal supply, Export, Summary invoice, Self-billed.
- **Invoice number** - leave it empty to take the next number from your
  numbering when the invoice is issued.

<!-- source: resources/views/portal/invoices/partials/vat-treatment.blade.php; resources/views/countries/saudi-arabia/invoice/create.blade.php -->

## Drafts

A draft has no number, counter, hash or QR. You can change it (**Edit draft**)
or throw it away (**Delete draft**). Deleting a draft loses nothing from your
invoice sequence. An issued invoice can never be edited or deleted. You
correct it with a credit or debit note.

**Issue invoice** asks you to confirm. The invoice is then numbered, signed and
recorded for ZATCA. This cannot be undone.

<!-- source: resources/views/portal/invoices/show.blade.php; app/Domains/Invoice/Enums/InvoiceStatus.php -->

## Statuses

An invoice has one of four statuses:

| Status | Meaning |
|---|---|
| Draft | Not issued. Can be changed or deleted. |
| Issued | Numbered and signed. Waiting for ZATCA's answer. |
| Accepted | ZATCA accepted it: a standard invoice cleared, a simplified one reported. |
| Rejected | ZATCA refused it. It is not a valid tax invoice. |

The **Filing with ZATCA** panel on the invoice says where its filing is:

| You see | What it means | What to do |
|---|---|---|
| Waiting to be sent | Queued. It is sent automatically. | Nothing. |
| Sent; ZATCA's answer has not been received | If no answer arrives within 15 minutes, the same signed invoice is sent again and ZATCA's answer is recorded. | Nothing; an administrator may also use **Send again** once it has waited 15 minutes. |
| Cleared / Reported | Filed. | Nothing. |
| Accepted with warnings | Filed. ZATCA gave advice, listed on the invoice in plain words, with ZATCA's own text under **The authority's own words**. | Fix it on future invoices. |
| Not filed yet: it did not get through | An outage or a refused connection. | An owner or admin presses **Send again**. Every check runs again first. |
| Rejected by ZATCA | A verdict. It is not sent again. Each rule it broke is explained on the invoice. | Issue a corrected invoice. |

**Submissions** lists every filing with the same states, filters and the
**Send again** button for failed ones.

If ZATCA refuses a device's certificate, filing from that device pauses. Its
new invoices wait and are filed once the certificate is renewed under
**Device certificates**.

<!-- source: app/Domains/Compliance/Countries/SaudiArabia/Presentation/Presentation.php filing(); resources/views/countries/saudi-arabia/invoice/panel.blade.php; resources/views/countries/saudi-arabia/submissions.blade.php; app/Domains/Compliance/Countries/SaudiArabia/Models/InvoiceSubmission.php isResendable(); app/Domains/Compliance/Countries/SaudiArabia/Onboarding/RuleMeanings.php -->

### Late reporting

A simplified invoice must be reported within 24 hours. **Late reporting** lists
any that were not. The platform keeps trying. ZATCA asks you to notify it
through a form on its website, and again once the invoice is reported. Record
each notice on that page. **Evidence of attempts (CSV)** downloads the record.

<!-- source: resources/views/countries/saudi-arabia/late-reporting.blade.php -->

## Credit and debit notes

1. Open the issued invoice and press **Credit note** or **Debit note**. The form is filled in from the invoice.
2. Choose the **Reason** from the official list.
3. Issue it.

Rules:

- A note is issued only after ZATCA has **accepted** the original. Until then
  the invoice shows when a note can be made instead of the **Credit note** and
  **Debit note** buttons, and a draft note against it says which original it
  waits for and offers no **Issue**.
- A rejected invoice cannot be corrected with a note. Issue a new invoice instead.
- Issue a note within 15 days of the end of the month in which the change
  happened. One note may correct several invoices.
- Credit notes never come to more than the invoice and its debit notes.
  Notes that name it among several invoices count too.
- A note counts in the VAT period of its own date, never in the original's.
- Your company may require a second person to approve notes. The draft note
  then shows **Send for approval** instead of **Issue**. See
  [TEAM-AND-SETTINGS.md](TEAM-AND-SETTINGS.md#approvals-for-notes).

<!-- source: app/Domains/Compliance/Countries/SaudiArabia/Rules/VatPeriodTracker.php notYetAccepted(); app/Domains/Compliance/Countries/SaudiArabia/Rules/Adjustments.php refusal(); resources/views/countries/saudi-arabia/invoice/actions.blade.php; app/Domains/Invoice/Support/AdjustmentReason.php -->

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
- The notes are dated today, so they count in the current VAT period. Against
  an invoice of an earlier period they show as adjustments. A note on several
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
  in the UAE (Executive Regulation Art 59(13)(b)). Drafted with an issue
  date after that, it is still drafted, with a warning that it is late and
  the day it was due.
- Saudi Arabia: its VAT counts in the return of the month it covers, where
  the deliveries were made, not the month it is issued in (VAT Implementing
  Regulations Art 23; Art 53(4) lets the invoice come later, not the tax).
  October's summary issued on 10 November is in October's return.
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

## Printing, PDF and QR

- **Print** opens a printable copy. The print screen can switch paper for one invoice.
- Paper sizes: A4, Letter, A5, 80 mm and 58 mm receipt rolls. Your own choice
  comes first, then the branch, then the organization (**Printing**).
- **PDF** and **Send invoice** (email the PDF to the buyer) appear once the
  invoice may be given to the buyer. A standard invoice must be cleared first.
  A rejected invoice is never shared.
- The ZATCA QR code is on every issued invoice. **Download QR (PNG)** or
  **(SVG)**. Your own business QR code can print beside it, labelled. It is
  never part of ZATCA's QR.

<!-- source: app/Domains/Invoice/Enums/PaperSize.php; resources/views/portal/printing.blade.php; app/Domains/Compliance/Countries/SaudiArabia/Presentation/Presentation.php assertShareable() -->

## History, search and exports

**Invoices** lists everything issued. **Credit and debit notes** lists the corrections.

- Filters: search by number or buyer, issue date, total, buyer, status,
  invoice type, document type, branch, till, currency and who issued it.
- Sort by newest, issue date, number, amount, buyer or status.
- **Save view** keeps a filter set for yourself.
- **Export CSV** downloads exactly what the filters show.

**Reports** shows the period's sales as the VAT return groups them, with
**Invoices CSV** and **VAT summary CSV**. In Saudi Arabia it also has **Daily
close by device** (a Z-report per till and branch) and **Insurer receivables**.
It opens on this month on your country's clock. A document in another
currency counts as its own VAT in riyals, converted once and rounded to the
halala, so each box adds up to the documents in it. **Filing status with
ZATCA** shows how many of the documents in the figures are cleared or
reported, not answered yet, or not filed, and what ZATCA rejected, which is
left out.

**E-invoice archive (ZIP)** on the same page (Saudi Arabia; owners, administrators
and the accountant) downloads the period's issued documents, up to 31 days at
a time: each signed XML, ZATCA's cleared XML for a standard invoice, the PDF, a
`manifest.csv` (number, UUID, ICV, hash, PIH, status and ZATCA's answer) and
`SHA256SUMS`. Files are named as ZATCA names them: seller VAT number, issue
date and time, then the invoice number. Keep it in your own archive; the
download is on your activity record.

**Import** brings in invoices, customers and products from CSV. Every row is
checked first. Invoices are saved as drafts, never issued.

<!-- source: app/Domains/Invoice/Services/InvoiceHistory.php; routes/web.php (portal reports, imports) -->

## More

- Schedules (repeat an invoice, instalment plans): **Schedules**.
- End-to-end flows: [WORKFLOWS.md](WORKFLOWS.md).

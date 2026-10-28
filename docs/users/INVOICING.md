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
| Accepted with warnings | Filed. ZATCA gave advice, listed on the invoice. | Fix it on future invoices. |
| Not filed yet: it did not get through | An outage or a refused connection. | An owner or admin presses **Send again**. Every check runs again first. |
| Rejected by ZATCA | A verdict. It is not sent again. | Issue a corrected invoice. |

**Submissions** lists every filing with the same states, filters and the
**Send again** button for failed ones.

If ZATCA refuses a device's certificate, filing from that device pauses. Its
new invoices wait and are filed once the certificate is renewed under
**Device certificates**.

<!-- source: app/Domains/Compliance/Countries/SaudiArabia/Presentation/Presentation.php filing(); resources/views/countries/saudi-arabia/invoice/panel.blade.php; resources/views/countries/saudi-arabia/submissions.blade.php; app/Domains/Compliance/Countries/SaudiArabia/Models/InvoiceSubmission.php isResendable() -->

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
  it stays a draft and says which original it waits for.
- A rejected invoice cannot be corrected with a note. Issue a new invoice instead.
- Issue a note within 15 days of the end of the month in which the change
  happened. One note may correct several invoices.
- Your company may require a second person to approve notes. See
  [TEAM-AND-SETTINGS.md](TEAM-AND-SETTINGS.md#approvals-for-notes).

<!-- source: app/Domains/Compliance/Countries/SaudiArabia/Rules/VatPeriodTracker.php notYetAccepted(); resources/views/countries/saudi-arabia/invoice/actions.blade.php; app/Domains/Invoice/Support/AdjustmentReason.php -->

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

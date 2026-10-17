# UAE e-invoicing: compliance rules

This page lists the rules the platform enforces for UAE documents, where each
one is checked, and the official text behind it. It covers what the platform
does today. For an overview of the model and setup, see [README.md](README.md).

## Sources

| Short name | Document |
|---|---|
| Guidelines | UAE Electronic Invoicing Guidelines, version 1.1 (Ministry of Finance, 1 June 2026) |
| Mandatory fields | UAE Electronic Invoice Mandatory Fields, version 1.0 (Ministry of Finance, 23 February 2026) |
| MD 243/2025 | Ministerial Decision No. 243 of 2025 on the Electronic Invoicing System |
| MD 244/2025 | Ministerial Decision No. 244 of 2025 on the Implementation of the Electronic Invoicing System |
| MR 66/2026 | Ministerial Decision No. 66 of 2026, amending Article 5(1)(a) of MD 244/2025 |
| MD 168/2026 | Ministerial Decision No. 168 of 2026 on the accreditation of service providers |
| PINT AE | Peppol PINT AE Billing 1.0.4 and PINT AE Self-Billing, https://docs.peppol.eu/poac/ae/pint-ae/ and https://docs.peppol.eu/poac/ae/pint-ae-sb/ |

Rule identifiers in the form `IBR-nnn-AE` and `ALIGNED-IBRP-...` are PINT AE
schematron rules. Each one has a page under the specification's `rule/` path.

## Where rules are checked

The rules are checked in four places, and a document must get past all of
them.

1. **When an invoice is drafted** (the UAE module's `Rules\InvoiceRules`, on
   `POST /api/compliance/ae/invoices`, the ERP pipeline and the portal form).
   The request is checked for shapes and for combinations the UAE does not
   allow. A failure answers 422 with field errors, and nothing is stored.
2. **When seller details are saved** (the UAE module's `Rules\SellerRules`
   and `UaeTin`, on the company page, sign-up and the API). The TRN, TIN,
   endpoint, emirate and legal registration are checked in PINT AE's shapes.
3. **When an invoice is sent** (`InvoiceIssuer`, `PintMapper`,
   `MandatoryFields`). The invoice is mapped to a PINT document and checked
   against the Ministry's mandatory-field list and the scenario rules. Its
   totals must also match the invoice's stored totals. If any check fails, the
   invoice stays a draft, keeps no number, and no document is stored. Every
   problem is returned in a single answer.
4. **In CI** (`PintConformanceTest`). The official PINT AE schematrons and the
   UBL 2.1 schema are run over documents built for every supported kind and
   scenario. This check is not run in production. Steps 1 to 3 are written so
   that a document that passes them passes the schematrons.

The service provider validates each document again before sending it, and so
does the buyer's provider.

## Scope

| Rule | Source | Platform behaviour |
|---|---|---|
| Every Person doing business in the UAE is in scope, whether or not it is registered for VAT, unless excluded | Guidelines 6; MD 243/2025 | A UAE organization can issue tax documents (with a TRN) or commercial ones (with a TIN only) |
| B2B, B2G, G2B and G2G are in scope. B2C is not | Guidelines 6.2 | There is no simplified type. Every document names a buyer, and requests with `type` other than `standard` are refused |
| Exclusions cover sovereign activities, certain airline supplies, and VAT-exempt financial services | Guidelines 7 | Not modelled. The organization decides not to issue these through the platform |
| Importing concerned goods and services under the reverse charge needs no e-invoice | Guidelines 10.5.1 | Category `AE` is for the domestic reverse charge only |

## Document kinds

| Rule | Source | Where |
|---|---|---|
| There are six kinds: tax invoice and credit note, commercial invoice and credit note, and self-billed tax invoice and credit note | Guidelines 10.1 | `PintDocumentCategory` |
| Type codes are 380, 381, 480, 81, 389 and 261 | PINT AE code lists UNCL1001-inv and -cn; PINT AE Self-Billing | `PintAe::typeCode()` |
| `CustomizationID` is `urn:peppol:pint:billing-1@ae-1` with `ProfileID` `urn:peppol:bis:billing`. For self-billing, they are `urn:peppol:pint:selfbilling-1@ae-1` and `urn:peppol:bis:selfbilling` | PINT AE | `PintAe` |
| There is no debit note. An increase is a new invoice | Guidelines 10.1 | The request allows `invoice` and `credit_note` only, and `PintMapper` refuses anything else |
| No self-billing of commercial documents | Guidelines 10.3 | The API names only `tax`, `commercial` and `self_billing`, and self-billing maps to tax documents only |
| A credit note is issued for a cancellation, a reduced or returned consideration, or an error | MD 243/2025 Art. 6(2) | The credit reason code is required on a tax credit note (see below) |
| A negative amount payable is a credit note, not a negative invoice | Guidelines 10.4 (summary invoice) | The request refuses a discount larger than the lines. `MandatoryFields` refuses a negative amount due |

## Mandatory fields

`MandatoryFields` holds one named check for each field in the Mandatory
fields document, in that document's order:

- 51 fields for a tax invoice (section 4.1)
- 49 fields for a commercial invoice (section 4.2)

Some fields apply only in certain cases. In each such case, the specification
says when the field applies, and the check runs only then:

- **Payment due date.** Not required on a credit note, on a deemed supply, or
  when nothing is payable (IBR-127-AE). The request requires `due_date`
  otherwise, and it may not be earlier than `issue_date`.
- **Payment means type code.** Not required on a credit note or a deemed
  supply (IBR-191-AE).
- **Buyer tax identifier (TRN).** Required on a tax document for a buyer in
  the UAE. Not required for a buyer abroad or on an export.
- **Buyer legal registration.** Required on a commercial document
  (IBR-136-AE).
- **VAT line amount in AED and invoice line amount in AED.** Required on tax
  documents. An exempt line carries no VAT amount (IBR-163-AE).

Fields the platform computes, such as the totals and the VAT breakdown, are
present because the platform builds them. The only check on them is that the
document has at least one line.

## Seller and buyer identity

| Rule | Source | Where |
|---|---|---|
| A TRN is 15 digits, starts with `1` and ends with `03` | IBR-132-AE | Seller details, request (`buyer_vat_number` for a buyer in the UAE, `principal_id`), `MandatoryFields` |
| A TIN is 10 digits and starts with `1` | IBR-148-AE | `UaeTin`, `MandatoryFields` |
| The TIN is the first 10 digits of the TRN | Guidelines 2 | `UaeTin` refuses a TIN that differs from the TRN's prefix. If a TRN is saved without a TIN, the TIN is set from it |
| A seller with no TRN gives its TIN instead | Mandatory fields 4.2 item 15 | Seller details require one or the other. The document carries the TIN only when there is no TRN |
| A TRN belongs to one organization | — | Seller details refuse a TRN already held by another organization |
| The participant identifier is `0235` followed by the 10-digit TIN, issued during onboarding | Guidelines 3, 9 | `endpoint_id` must match `0235:` plus 10 digits. It is stored as issued, never worked out from the TRN |
| The seller's endpoint scheme is `0235` | Mandatory fields | `MandatoryFields` |
| An address names an emirate: `AUH`, `DXB`, `SHJ`, `UAQ`, `FUJ`, `AJM` or `RAK` | IBR-128-AE | Seller `emirate`, `buyer_subdivision` for a buyer in the UAE, `MandatoryFields` |
| A legal registration type is `TL` (trade licence), `EID` (Emirates ID), `PAS` (passport) or `CD` (Cabinet decision) | IBR-172-AE, IBR-173-AE | Seller details, request, `MandatoryFields` |
| A trade licence names its issuing authority, and a passport names its issuing country as a two-letter code | IBR-012-AE; Guidelines 12.2 item 20 | Seller details, `MandatoryFields` |
| A credit transfer names the account to pay into | IBR-192-AE | `payment_account` (IBAN shape) in the seller details. With payment means `30`, it must be set. With means `30`, `31`, `42` or `58`, it is written when set |

## Buyer endpoints

| Situation | Buyer endpoint | Source | Where |
|---|---|---|---|
| The buyer is on e-invoicing | Its own `0235:` plus TIN, or another Peppol scheme for a buyer abroad | Guidelines 5.2 | `buyer_endpoint` is required unless the document is a deemed supply or an export |
| A domestic buyer not yet on e-invoicing | `0235:9900000098` | Guidelines 10.2.2 | Sent by the caller. The buyer's TRN is then required (IBR-135-AE). The supplier must also give that buyer a regular tax invoice, which the platform does not do for UAE invoices |
| A deemed supply | Always `0235:9900000097` | Guidelines 10.4 (2) | `PintMapper` sets it whatever the request says |
| An export buyer with no Peppol identifier | `0235:9900000099` | Guidelines 10.4 (8) | `PintMapper` sets it when an export names no endpoint |

## Tax categories

| Code | Meaning | Rate on the document | VAT charged | Further requirements |
|---|---|---|---|---|
| `S` | Standard rate | 5, and nothing else (IBR-190-AE) | Yes | — |
| `Z` | Zero rated | 0 | No | — |
| `E` | Exempt | None (ALIGNED-IBRP-E-05) | No, and no VAT amount on the line (IBR-163-AE) | An exemption reason code: `DL8.46.1` to `DL8.46.4` |
| `O` | Outside the scope of VAT | None (ALIGNED-IBRP-O-05) | No | — |
| `AE` | Domestic reverse charge | 5 is stated (ALIGNED-IBRP-AE-09-AE) | No (IBR-108-AE) | The goods type (`DL8.48.8.2`, `DL8.48.8.1`, `DL8.48.3.1`, `DL8.48.3.2` or `DL8.48.3.3`), the item's GTIN (8 to 14 digits), and the buyer's TRN |
| `N` | Margin scheme ("standard rate additional VAT") | 5 is stated | No. The amount shown is 0 (Guidelines 10.4 (3)) | Under the margin-scheme flag every line is `N` (IBR-116-AE) |

These rules also apply:

- A commercial document may carry only `E`, `O` and `Z` lines (IBR-122-AE).
- A document discount is split across the categories in proportion to each
  category's net amount, with any remainder going to the largest. Each
  allowance names one category (ALIGNED-IBRP-032). The discount never falls
  on `AE` or `N` lines, because an allowance in `N` is refused (IBR-115-AE).
  If those are the only lines, the discount is refused.
- A line's stored category is read only through `PintTaxCategory`. Saudi
  shapes are refused when a UAE invoice is drafted: a standard rate other than
  5, or a `VATEX-SA-...` exemption code.

## Transaction type flags

`transaction_flags` is the eight-character transaction type code (BTAE-02,
written as `cbc:ProfileExecutionID`). Each position is `1` where the scenario
applies, and `00000000` means none applies (IBR-154-AE). More than one
scenario can apply to a single document (Guidelines 10.4).

| Position | Scenario | What it requires | Allowed on a commercial document |
|---|---|---|---|
| 1 | Free zone | `beneficiary_id` | Yes |
| 2 | Deemed supply | The reserved endpoint `0235:9900000097`. No due date or payment means | No (IBR-157-AE) |
| 3 | Margin scheme | Every line in category `N` | No (IBR-157-AE) |
| 4 | Summary invoice | Its period: `supply_date` and `supply_end_date` | No (IBR-157-AE) |
| 5 | Continuous supply | — | Yes |
| 6 | Disclosed agent | `principal_id`: the principal's TRN, which is not the seller's | Yes |
| 7 | E-commerce | A delivery address with street, city and subdivision. The buyer's address is used when none is given | Yes |
| 8 | Export | A delivery address outside the UAE with street, city and subdivision. The buyer's address is used when none is given. If there is no buyer endpoint, the reserved export endpoint is used | Yes |

The delivery-address requirements for e-commerce and export come from
IBR-142-AE and IBR-152-AE.

## Credit notes

| Rule | Source | Where |
|---|---|---|
| A tax credit note gives a reason code: `DL8.61.1.A` to `DL8.61.1.E`, or `VD` (volume discount) | IBR-158-AE; Guidelines 12.2 item 14 | Request, `MandatoryFields` |
| A credit note names the invoice it corrects (`billing_ref`), unless it is a volume discount | IBR-055-AE | Request, `MandatoryFields` |
| A credit note has no due date, VAT point date or payment means | IBR-124-AE, IBR-127-AE, IBR-191-AE | `PintMapper` leaves them out |
| A credit note may cover only part of an invoice | Guidelines 12.2 item 4 | Allowed |

## Amounts, currency and rounding

| Rule | Source | Where |
|---|---|---|
| Rounding applies only at the invoice total, to 2 decimals, and never at category or line level | Guidelines 12.2 item 5 | `PintMapper` works in bcmath at 6 decimal places. Each category's VAT stays exact, and the document's total tax is rounded once, half away from zero |
| The VAT amount and the amount payable for each line are stated in AED, whatever the document currency | Guidelines 12.2 item 16; Mandatory fields 4.1 | `PintMapper` computes them, converting at the invoice's exchange rate |
| A document in another currency states its tax accounting currency (AED) and its total with VAT in AED | Guidelines 12.2 item 17; VAT Decree-Law Art. 69 | `exchange_rate` greater than 0 is required whenever `currency` is not `AED`. The tax total and the gross total in AED are computed |
| Net price times quantity agrees with the line net amount | IBR-147-AE | Prices are carried to 6 decimals where the rounded unit price would not agree |
| A VAT point date is given only when the supply date differs from the issue date | Guidelines 12.2 item 23; IBR-141-AE | Written only when `supply_date` is earlier than `issue_date` |
| Prices are net | — | UAE drafts are always `prices_include_vat: false` |

**The stored totals must match the document.** The invoice's stored `total`
and `tax_amount` are what the PDF and the API show. If they differ from the
document's computed amount payable and VAT, sending is refused with one
problem per differing amount, rather than letting one invoice carry two
amounts.

## Issuing and transmission

| Rule | Source | Platform behaviour |
|---|---|---|
| The issuer must appoint exactly one accredited service provider, for both sending and receiving | MD 243/2025 Art. 5; Guidelines 6.1 | A deployment configures one provider. Organizations are told apart by their endpoint |
| An e-invoice or e-credit note is issued and transmitted within 14 days of the Date of Business Transaction: the earlier of the date the transaction occurred and the date payment was received. A VAT registrant follows the VAT Law's timeline, which for a tax invoice is also 14 days from the date of supply | MD 243/2025 Art. 6(5), 6(4) | **Recorded, not refused**, because issuing late is still better than not issuing. See [The 14-day deadline](#the-14-day-deadline) |
| One document per invoice | — | `PeppolPreparer` locks the invoice row and returns the existing document. Sending twice never makes a second document |
| Each document carries a UUID (`cbc:UUID`) | PINT AE IBR-193-AE (fatal); Guidelines 5.2, row 7 | The Guidelines make the service provider responsible for generating it. Because the schematron refuses a document without one, the platform writes the invoice's own UUID, so the document is valid as stored. A provider may assign its own |
| A failure of the issuer's system is reported to the authority within 2 business days | MD 243/2025 Art. 12 | An operator's obligation. The platform logs provider failures but does not notify the authority |
| Penalties apply only once implementation is mandatory for the Person | Guidelines 8.2, 11 | — |

### The 14-day deadline

The source is MD 243/2025 Art. 6(5), with Art. 6(4) for a VAT registrant.
`IssueDeadline` applies it as follows:

- **The transaction date** is the invoice's `supply_date`, or its
  `issue_date` when there is no supply date. The platform does not record
  payments, so an earlier payment date cannot move it.
- **The issue date** is the date of `issued_at`, the moment the invoice was
  issued, read on the UAE's clock (Asia/Dubai).
- **On time** means 14 days or fewer between them. 15 days or more is late.
- **A late invoice is still issued and sent.** Its document is stored with
  `issued_late` set, and the platform says so in the `warnings` of
  `POST /api/compliance/ae/invoices/{invoice}/send`, of
  `GET /api/compliance/ae/invoices/{invoice}/status` and of the pipeline's
  result. The portal's invoice page shows it too.
- **It is decided at issue.** An invoice issued with `auto_submit` false is
  sent later, but the deadline covers transmission as well, so send it
  within the same 14 days.

## Retention

The source is Guidelines 5.4 and Appendix 4, read with MD 243/2025 Art. 11.
Documents must be kept for these periods:

- 5 years after the tax period, for a taxable person.
- 5 years from the end of the calendar year, for anyone else.
- 7 years, for real estate records.
- 4 further years in a dispute or audit.
- 1 further year after a voluntary disclosure made in the fifth year.

Records must stay complete, readable and retrievable on request. They may be
stored outside the UAE if they can be produced promptly. Storing documents
with a provider does not move the obligation away from the taxpayer.

What the platform does:

- Every sent and received document is kept in `peppol_documents`, with its
  XML exactly as it travelled.
- The application has no path that deletes them.
- The database refuses to delete an organization that still has documents.

The retention period itself, backups and restore testing are the operator's
responsibility. See [BACKUP-AND-RESTORE.md](../BACKUP-AND-RESTORE.md).

## Regulatory timeline

The phased implementation is set by MD 244/2025. The table follows Guidelines
section 8, as amended by MR 66/2026.

| Who | Appoint a service provider by | Implement by |
|---|---|---|
| Pilot participants (by invitation) | — | Pilot from 1 July 2026 |
| Anyone, voluntarily | — | From 1 July 2026 |
| Revenue of AED 50,000,000 or more | 30 October 2026 (MR 66/2026; Guidelines v1.1 gave 31 July 2026) | 1 January 2027 |
| Revenue under AED 50,000,000 | 31 March 2027 | 1 July 2027 |
| Government entities | 31 March 2027 | 1 October 2027 |

Transactions between members of one VAT group have a 24-month grace period
from 1 January 2027 (Guidelines 6.3.2.1). The Ministry of Finance publishes
the current position at https://mof.gov.ae/einvoicing/.

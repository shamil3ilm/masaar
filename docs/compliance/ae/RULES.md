# UAE e-invoicing: compliance rules

Audience: engineers changing UAE behaviour, and compliance reviewers
checking it against the official texts.

The rules the platform enforces for UAE documents, where each is checked,
and the text behind it. The model and setup are in [README.md](README.md).

## Sources

| Short name | Document |
|---|---|
| Guidelines | UAE Electronic Invoicing Guidelines, version 1.1 (Ministry of Finance) |
| Mandatory fields | UAE Electronic Invoice Mandatory Fields, version 1.0 (Ministry of Finance) |
| MD 243/2025, MD 244/2025 | Ministerial Decisions No. 243 of 2025 (the system) and No. 244 of 2025 (its implementation) |
| MD 168/2026 | Ministerial Decision No. 168 of 2026 on accrediting service providers |
| VAT Decree-Law; Executive Regulation | Federal Decree-Law No. 8 of 2017; Cabinet Decision No. 52 of 2017, as amended |
| PINT AE | Peppol PINT AE Billing 1.0.4 and Self-Billing (https://docs.peppol.eu/poac/ae/pint-ae/) |

`IBR-nnn-AE` and `ALIGNED-IBRP-...` are PINT AE schematron rules; each id
here was checked against the published billing schematrons and all are
`fatal`. MD 243/2025 articles other than Art. 11 (quoted in Guidelines
Appendix 4) are cited as the Guidelines and the code cite them, not checked
against the decision's own text.

## Where rules are checked

1. **Drafting** (`Rules\InvoiceRules`; API, pipeline, portal): shapes and
   forbidden combinations. A failure answers 422 and stores nothing.
2. **Seller details** (`Rules\SellerRules`, `Rules\UaeTin`): TRN, TIN,
   endpoint, emirate, legal registration.
3. **Issuing** (`IssuingStep`, `PintMapper`, `MandatoryFields`): the mapped
   document against the mandatory fields and scenario rules, and its totals
   against the invoice's stored `total` and `tax_amount`. Any failure leaves
   a draft with no number and no document, every problem in one answer.
4. **CI** (`PintConformanceTest`): the official schematrons and UBL 2.1
   over every kind and scenario. Steps 1–3 are written so a document that
   passes them passes these.

## Scope and documents

| Rule | Source | Platform behaviour |
|---|---|---|
| Every Person doing business in the UAE is in scope, VAT-registered or not, unless excluded | Guidelines 6; MD 243/2025 | Tax documents with a TRN, commercial ones with a TIN only |
| B2B, B2G, G2B, G2G in scope; B2C not | Guidelines 6.2 | No simplified type; `type` other than `standard` is refused |
| Exclusions (sovereign, certain airline, exempt financial services) | Guidelines 7 | Not modelled |
| Six kinds; type codes 380, 381, 480, 81, 389, 261 | Guidelines 10.1; PINT AE code lists | `PintDocumentCategory`, `PintAe::typeCode()` |
| `CustomizationID` `urn:peppol:pint:billing-1@ae-1` / `urn:peppol:pint:selfbilling-1@ae-1` | PINT AE | `PintAe` |
| No debit note; no self-billed commercial document | Guidelines 10.1, 10.3 | Only `invoice` and `credit_note` |
| A negative amount payable is a credit note | Guidelines 10.4 | `MandatoryFields` refuses a negative amount due |

## Mandatory fields

`MandatoryFields` holds one named check per field of the Mandatory fields
list, in its order: 51 for a tax invoice (4.1), 49 for a commercial one
(4.2). Conditional fields are checked only where they apply: due date (not
on a credit note, deemed supply or nothing payable, IBR-127-AE), payment
means (IBR-191-AE), buyer TRN (tax document, buyer in the UAE), buyer legal
registration (commercial, IBR-136-AE), line VAT and amount in AED (tax
documents; none on an exempt line, IBR-163-AE).

## Seller and buyer identity

| Rule | Source | Where |
|---|---|---|
| TRN: 15 digits, starts `1`, ends `03` | IBR-132-AE | Seller details, request, `MandatoryFields` |
| TIN: 10 digits, starts `1`, the TRN's first 10; given when there is no TRN | IBR-148-AE; Mandatory fields 4.2 | `UaeTin` |
| A TRN belongs to one organization | — | Seller details refuse a claimed TRN |
| Endpoint `0235:` + 10 digits, stored as issued | Guidelines 3, 9 | `endpoint_id` |
| Emirate `AUH`, `DXB`, `SHJ`, `UAQ`, `FUJ`, `AJM`, `RAK` | IBR-128-AE | Seller, buyer in the UAE |
| Legal registration type `TL`, `EID`, `PAS`, `CD` | IBR-173-AE | Seller details, request |
| Trade licence names its authority; passport its country | IBR-172-AE; IBR-012-AE | `MandatoryFields` |
| Credit transfer names the account | IBR-192-AE | `payment_account` |
| Buyer not yet on e-invoicing: `0235:9900000098`, buyer TRN required | Guidelines 10.2.2; IBR-135-AE | The caller sets it; the printed tax invoice it also needs is the invoice's PDF <!-- Presentation/PrintedInvoice.php --> |
| Deemed supply: always `0235:9900000097`; export with no endpoint: `0235:9900000099` | Guidelines 10.4 (2), (8) | `PintMapper` |

## Tax categories

| Code | Meaning | Rate | VAT | Further |
|---|---|---|---|---|
| `S` | Standard | 5 only (IBR-190-AE) | Yes | — |
| `Z` | Zero rated | 0 | No | — |
| `E` | Exempt | none (ALIGNED-IBRP-E-05) | No line VAT (IBR-163-AE) | Reason `DL8.46.1`–`DL8.46.4` |
| `O` | Out of scope | none (ALIGNED-IBRP-O-05) | No | — |
| `AE` | Domestic reverse charge | 5 stated | Amount 0 (ALIGNED-IBRP-AE-09-AE) | Goods type, GTIN, buyer TRN |
| `N` | Margin scheme | 5 stated | Amount 0 (IBR-108-AE) | Every line `N` under the flag (IBR-116-AE) |

A commercial document carries only `E`, `O`, `Z` (IBR-122-AE). A document
discount is split across categories by net amount, each allowance naming
one (ALIGNED-IBRP-032), never on `AE` or `N` (IBR-115-AE). Saudi shapes
(another standard rate, `VATEX-SA-...`) are refused at drafting.

## Transaction type flags

`transaction_flags` is BTAE-02 (`cbc:ProfileExecutionID`), eight `0`/`1`
positions (IBR-154-AE); several may apply. Positions 2–4 are not allowed on
a commercial document (IBR-157-AE).

| Pos | Scenario | Requires |
|---|---|---|
| 1 | Free zone | `beneficiary_id` |
| 2 | Deemed supply | Reserved endpoint; no due date or payment means |
| 3 | Margin scheme | Every line `N` |
| 4 | Summary invoice | `supply_date` and `supply_end_date` |
| 5 | Continuous supply | — |
| 6 | Disclosed agent | `principal_id`, a TRN not the seller's |
| 7 | E-commerce | Delivery street, city, subdivision (IBR-142-AE) |
| 8 | Export | Delivery address outside the UAE (IBR-152-AE) |

## Credit notes, amounts and currency

| Rule | Source | Where |
|---|---|---|
| Tax credit note reason `DL8.61.1.A`–`E` or `VD` | IBR-158-AE | Request, `MandatoryFields` |
| Names the invoice it corrects, unless `VD` | IBR-055-AE | Request |
| No VAT point date, due date or payment means on a credit note | IBR-124-AE, IBR-127-AE, IBR-191-AE | `PintMapper` |
| Rounding only at invoice total, 2 decimals | Guidelines 12.2 item 5 | bcmath at 6 places, one rounding |
| Line VAT and amount payable in AED; tax total in AED for other currencies | Guidelines 12.2 items 16–17; VAT Decree-Law Art. 69 | `exchange_rate` required when `currency` is not `AED` |
| Net price × quantity = line net | IBR-147-AE | Prices kept to 6 decimals where needed |
| VAT point date only when supply precedes issue | Guidelines 12.2 item 23; IBR-141-AE | `PintMapper` |

## Issuing and transmission

| Rule | Source | Platform behaviour |
|---|---|---|
| Appoint one ASP for sending and receiving | MD 243/2025 Art. 5; Guidelines 6.1 | `provider_appointments`; from the start date, issuing is refused without one <!-- Issuing/IssuingStep.php prepare() --> |
| E-invoicing applies from the organization's own start date | Guidelines 8.2, 8.3; MD 244/2025 | `EinvoicingStart`; before it, `not_transmitted` and never sent ([README](README.md#before-the-e-invoicing-start-date)) |
| Issue and transmit within 14 days of the transaction | Federal Decree-Law No. 8 of 2017 Art 67(1) (a tax invoice within 14 days of the date of supply); MD 243/2025 Art. 6(5), 6(4) | `IssueDeadline`: supply date (else issue date) to the issue moment on the UAE clock; over 14 days is recorded as `issued_late` and warned, never refused |
| One document per invoice | — | `PeppolPreparer` locks the invoice row |
| A UUID per document (`cbc:UUID`) | IBR-193-AE; Guidelines 5.2 row 7 | Generated per document (`document_uuid`), not the invoice id <!-- Document/PeppolPreparer.php --> |
| A system failure is reported to the authority within 2 business days | MD 243/2025 Art. 12 | The operator's duty; not notified by the platform |

Statuses after issue are in
[FILING-STATES.md](../../architecture/FILING-STATES.md#4-the-uae-network-document).
Input tax is recovered on payment in the VAT return; see
[BOOKS-AND-FINANCE.md](../../architecture/BOOKS-AND-FINANCE.md).

## Retention

Guidelines 5.4 applies Tax Procedures Executive Regulation Art. 3(1): five
years after the tax period (taxable person) or the calendar year (others),
seven for real estate, four more in a dispute or audit, one more after a
fifth-year voluntary disclosure. MD 243/2025 Art. 11 says "within the
State"; the Guidelines read it as retrievable on request "irrespective of
the geographic location of the servers" (Appendix 4). Keeping the
e-invoice is also a condition of input tax recovery (VAT Decree-Law
Art. 55(1)(c)); Art. 78 lists the records to keep.

The platform keeps every sent and received document in `peppol_documents`
with its XML as it travelled, has no delete path, and the database refuses
to delete an organization that has documents
(`restrictOnDelete`). The retention period itself and backups are the
operator's ([BACKUP-AND-RESTORE.md](../../operations/BACKUP-AND-RESTORE.md)).
<!-- database/migrations/0480_peppol.php -->

## Roll-out

MD 244/2025 and Guidelines section 8 set a pilot, a voluntary phase, then
mandatory phases by revenue band and for government entities, with a grace
period for intra-VAT-group transactions (6.3.2.1). No dates here: see
https://mof.gov.ae/einvoicing/. `Rules\EinvoicingPhases` holds the
Guidelines v1.1 phases as a hint only.

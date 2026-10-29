# Saudi Arabia — compliance rules as implemented

Audience: engineers changing the Saudi module, and reviewers checking which
official rule each refusal rests on.

Sources: the E-Invoicing XML Implementation Standard v1.2 and ZATCA's KSA
schematron in its SDK (rule ids), the E-Invoicing Detailed Guidelines, the VAT
Implementing Regulations (IR), the Guideline for Tax Invoicing and Records,
and the GCC VAT Agreement where the IR extracts are silent.
Paths are under `app/Domains/Compliance/Countries/SaudiArabia/`.

Rules are enforced in three places:

- **On the way in** — `Rules/InvoiceRules.php` (API and portal requests),
  with its after-validation checks in `Rules/DraftChecks.php` and
  `Rules/TaxTreatmentChecks.php` and a billed organization's identity and
  address in `Rules/BilledBuyer.php`; `Rules/SellerRules.php` and `Rules/SellerIdNumber.php` (company page): a 422,
  nothing stored.
- **Before a counter is spent** — `Issuing/IssuingStep::prepare()` runs
  `Rules/InvoiceValidator`, the sale-time check, a note's reference and the
  certificate checks. A refusal leaves a draft with no ICV, hash or chain place.
- **By the authority** — see [STATUS.md](STATUS.md). What an answer does to an
  invoice is in [FILING-STATES.md](../../architecture/FILING-STATES.md).

## Credit and debit notes

IR Article 54(4): a note carries "a reference to the sequential number of the
Tax Invoice issued in respect of the initial Supply" — BT-25, mandatory on 381
and 383 (BR-KSA-56); it also states its reason, KSA-10 (BR-KSA-17). Checked
before numbering (`IssuingStep::assertReferenceStands()` →
`Rules/VatPeriodTracker::validateAdjustmentPeriod()`). The original must be in
the same organization; **accepted by ZATCA** (standard cleared, simplified
reported) — otherwise the note stays a draft; not rejected; not itself a note;
of the note's type (Detailed Guidelines 4.3); and, for a credit note, not
over-credited. One note may reference several originals; each is checked.
The credit cap counts every standing credit note that shares an original -
a note on one invoice and a note on several alike - so the originals reached
through them are never credited for more than they charged
(`VatPeriodTracker::fitsAcrossNotes()`). Debit notes raise the cap by what they
charged: one on the invoice alone raises its own cap, one on several only
their combined cap. Notes are matched by number or row id, in any order.
A note against an original not yet accepted is refused before it is issued
or sent for approval, and stays a draft
(`app/Domains/Invoice/Services/NoteIssuing.php` `refusal()`).
<!-- Rules/VatPeriodTracker.php notYetAccepted(), refusalAgainst(), creditFits(), validateSeveral() -->

**Period.** A note is reported in its own period, never sent back to the
original's, even while that return is not yet due: IR Art 40(4) for an
increase, Art 40(5) for a decrease (that period's return or the return for
the period the note was issued in, whichever is later)
(`VatPeriodTracker::determineReportingPeriod()`). Whether the original is
from an earlier period decides only the field (Guideline for Tax Invoicing
and Records 7.1.1): a credit note against an original whose tax point is
before the period goes in the box's Adjustments, otherwise it nets in the
amount; a debit note always goes in the amount. A note on several originals
follows the earliest (`app/Domains/Invoice/Services/VatSummary.php`).

## Seller

| Rule | Text (abridged) | Where |
|---|---|---|
| BR-KSA-39 / 40 | Seller VAT number present; 15 digits, first and last `3` | `SellerRules`, `InvoiceValidator` |
| BR-KSA-08 | Seller ID BT-29 once, scheme BT-29-1 one of `CRN, MOM, MLS, 700, SAG, OTH`, alphanumeric; with several, the first in that order. Never the VAT number. The schematron applies it to every document, not only standard invoices; the platform refuses a standard invoice without it and requires the CR at onboarding. A branch's own CR, when it has one, on that branch's documents and its till's compliance samples ("the commercial registration of the branch in respect of which the Tax Invoice is being issued", XML Implementation Standard v1.2 p51-52) | `Rules/SellerIdentification`, `SellerIdNumber`, `Rules/SellerParty`; required on a standard invoice; written by `Document/UblPartyBuilder` |
| Seller address on a branch's documents | The registered (principal place of business) address on every device's documents and compliance samples alike; the Guideline for Tax Invoicing and Records (4.2) allows it or the relevant establishment's, and the name is always the head office's as registered (FAQ 15) | `Rules/SellerParty` |
| QR tag 1 | The Arabic seller name is at most 255 bytes of UTF-8, the TLV length (Security Features Implementation Standard, QR) | `SellerRules` |
| BR-KSA-F-13 (warning) | Other ID looks invalid — the platform refuses eight sequential or repeated digits | `SellerIdentification::problem()` |
| BR-KSA-09 / 37 / 66 | Full seller address; building number 4 digits; postal code 5 digits | `SellerRules`, `InvoiceValidator` |

Per scheme the platform also checks: `CRN` 10 digits, `700` 10 digits from 7,
others 1–50 letters and digits. Scheme in
`compliance_profile.seller_id_scheme` (default `CRN`), number in
`organizations.cr_number`.

**Legal names as registered.** Seller and buyer registration names enter the
XML and QR tag 1 exactly as registered, only made byte-safe — valid UTF-8,
NFC, control and invisible bidi characters removed, whitespace collapsed
(`Support/TextNormalizer::legalName()`, used by `Document/DocumentBuilder`).
`normalizeName()`, which folds letters, is for matching only.

## Buyer

| Rule | Text (abridged) | Where |
|---|---|---|
| BR-KSA-42 | Buyer name on a standard invoice and its notes | `InvoiceRules`, `InvoiceValidator` (cited there as BT-44) |
| BR-KSA-81 | Standard invoice: buyer VAT number or another buyer ID | `InvoiceRules`, `BilledBuyer`, `InvoiceValidator` |
| BR-KSA-14 | Buyer ID under an allowed scheme (`fatoora.validation.buyer_id_schemes`) | `InvoiceRules`, `InvoiceValidator` |
| BR-KSA-44 | Buyer VAT 15 digits `3…3`; a foreign tax number is sent as another buyer ID | `InvoiceRules`, `BilledBuyer`, `InvoiceValidator` |
| BR-KSA-46 | No buyer VAT number on an export | `DraftChecks` |
| BR-KSA-63 (warning) / 67 | Saudi buyer on a standard invoice: street, building number, district, city and a 5-digit postal code, all required at drafting (portal, API, pipeline) | `InvoiceRules`, `BilledBuyer` |
| BR-KSA-25 / 49 | `VATEX-SA-EDU` / `-HEA`: buyer name and national ID (`NAT`) | `InvoiceRules`, `DraftChecks`, `InvoiceValidator` (BR-KSA-25) |

## Header, dates, chain

| Rule | Text (abridged) | Where |
|---|---|---|
| BR-KSA-03 | UUID of letters, digits, dashes | `InvoiceValidator` |
| BR-KSA-04 | Issue date not in the future | Issue date and time are set to the moment of issue on the Kingdom's clock (Detailed Guidelines FAQ: the date "on which final Tax Invoice is generated") |
| BR-KSA-05 / 06 | Type code 388/381/383 (386 prepayment); KSA-2 `NNPNESB` | `Document/UblHeaderBuilder`, `InvoiceRules` |
| BR-KSA-07 / 31 | No self-billed export; simplified flags limited | `DraftChecks` |
| BR-KSA-15 | Standard tax invoice carries the supply date KSA-5 | `IssuingStep::beforeNumbering()` gives one without it its issue date |
| BR-KSA-35 / 36 | Supply end date needs, and is not before, the supply date | `InvoiceRules` |
| BR-KSA-71 / 72 | Simplified summary names buyer and period | `DraftChecks` |
| BR-KSA-92 | B2G invoice names its contract (BT-12) | `DraftChecks` |
| BR-KSA-26 / 33 / 34 / 61 | PIH and ICV | [ICV-PIH.md](ICV-PIH.md) |
| BR-KSA-27 – 30, 60 | QR on every document; stamp structure; stamp on simplified | `Signing/` — [HASHING-AND-SIGNING.md](HASHING-AND-SIGNING.md) |

A sale time more than `fatoora.sale_time.max_ahead_seconds` ahead is refused
(`Filing/TimestampValidator::saleTimeProblem()`).

## Lines, categories, amounts

| Rule | Text (abridged) | Where |
|---|---|---|
| BR-KSA-18 | Category `S`, `Z`, `E` or `O` | `InvoiceValidator` |
| BR-16 | At least one line | `InvoiceValidator` |
| BR-KSA-23 / 24 / 69 / 83, BR-KSA-CL-04 | Reason code and text from the category's own list, on lines and charges; at issue too, exactly the listed codes (no other `VATEX-SA-*`) | `TaxTreatmentChecks`, `InvoiceValidator` |
| BR-KSA-84, BR-S-05 / 07, BR-Z-05 / 07, BR-E-05 / 07, BR-O-09 | Standard rate on a standard-rated line or charge (`fatoora.validation.allowed_tax_rates`), 0% otherwise | `TaxTreatmentChecks`, `InvoiceValidator` |
| BR-KSA-11 / 13 | Out-of-scope line and charge at 0% | `InvoiceValidator`, `TaxTreatmentChecks` |
| BR-KSA-16 | UNTDID 4461 payment means | `InvoiceRules` |
| BR-KSA-19 – 22, BR-KSA-CL-06 | Charge reason as a UNCL 7161 code and in words | `InvoiceRules` with `app/Domains/Invoice/Support/ChargeRules.php`; written by `Document/UblTotalsBuilder`, `Document/UblLineBuilder` |
| BR-KSA-CL-01, BR-53 | ISO 4217 currency; VAT also in SAR | `InvoiceRules`, `InvoiceValidator` |
| BR-KSA-51 – 53, 73 – 80 | Line VAT amounts; prepayment lines. A prepayment is settled only by an invoice to the buyer it was issued to, and net of the credit notes against it, so BT-113 still equals the categories' KSA-31 + KSA-32 (Detailed Guidelines 8.1(c)-(d)) | `Document/UblLineBuilder`, `InvoiceRules`, `PrepaymentSettlement` |
| BR-CO-10 / 14 / 15 | Totals agree | `InvoiceValidator::validateAmounts()` |
| BR-KSA-F-04 / F-06 | Amounts positive (a reduction is a credit note), no discount beyond the lines; field lengths | `InvoiceValidator`, `DraftChecks` (F-04), `InvoiceRules` (F-06) |

## Language

IR Article 53(5) and 66(2): invoices are issued in Arabic, other languages as
translation. The Guideline for Tax Invoicing and Records (4.2.1) approves Latin
characters for proper names, including product names. The platform is
stricter, by the owner's decision: each line's Arabic description, the
buyer's Arabic name on a standard invoice and its notes, and any notes,
note reason, payment terms and charge reason must contain Arabic letters
(`Rules/ArabicText`); a Latin name typed into an Arabic field is refused,
unless a platform administrator gave the organization a grace period. The
seller's Arabic name is required at sign-up (`SellerRules`). The rendition's
title and captions are bilingual (`Rules/LanguageRules`).

## Certificates

- An **expired** certificate cannot stamp: refused before numbering
  (`IssuingStep::assertNotExpired()`, `CERT_EXPIRED`).
- Each stamp is checked against the CA's revocation list (Security Features
  Implementation Standard 2.2.1, requirement 14; `Signing/RevocationCheck`);
  a held list carries stamping for seven days. In ZATCA production the check
  fails closed: no current list, or none named or configured, holds the
  document as a draft with `CERT_REVOCATION_UNVERIFIED` and alerts; the off
  switch is ignored. Sandbox and simulation stamp with a logged warning.
- A credential from another ZATCA environment is never used
  ([README.md](README.md#environments)).

## Rule ids in refusals

Every `Rules/InvoiceValidator.php` refusal starts with the id of the rule it
enforces, as the ZATCA Schematron numbers it: BR-KSA-08 (seller ID),
BR-KSA-39 / 40 (seller VAT number), BR-06 (seller name), BR-KSA-09 / 66
(seller address, postcode), BR-KSA-03 (UUID), BR-KSA-05 (type code), BR-53
(exchange rate), BR-KSA-34 (ICV), BR-KSA-25 (buyer name), BR-KSA-44 and 81
(buyer VAT number or ID), BR-16 (lines), BR-KSA-18 (category), BR-KSA-23,
24, 69 and 83 (exemption reason code and text), BR-KSA-CL-04 (exemption code
list), BR-KSA-84, BR-Z-05, BR-E-05 and BR-KSA-11 (category rate), BR-CO-10,
14 and 15 (totals), BR-KSA-F-04 (amounts). A required field no BR rule
covers is cited by its field id instead: BT-1, BT-2, BT-3, BT-5, BT-25,
BT-44, BT-129, BT-146 and BT-153. The warnings (not onboarded, a large
amount, a 0% line, a future date) are the platform's own and cite no rule.
These ids appear in the `VALIDATION_FAILED` error's `details.errors` when
issuing is refused.

The portal explains each rule id ZATCA or the platform names in a plain
sentence, in English and Arabic, on warnings and rejections alike
(`Onboarding/RuleMeanings`, used by `Onboarding/ComplianceFindings`); a rule
with no entry of its own is explained by its family. The authority's own
words are kept beside it, and the API's rejection shape is unchanged.

## Special transactions

`Rules/SpecialSupplies.php` (each held by a ZATCA SDK case in
`SpecialTransactionConformanceTest`):

- **Summary tax invoice** from a customer's delivery notes in one calendar
  month (IR Art 53(4)): a standard invoice with KSA-2 position 6, the month
  as supply date and supply end date, due by the 15th of the next month. A
  simplified summary is left to the suppliers Art 53(7) names.
- **Contract billed in stages** (IR Art 20(2)): each stage is taxed on what
  it invoices; retention is taxed when invoiced or paid, at the latest twelve
  months on (Art 20(3)).
- **Disbursement line** (GCC VAT Agreement Art 26(6)(c)): out of scope,
  category `O` with `VATEX-SA-OOS` and a bilingual reason, only once the
  seller confirms it was paid in the customer's name, for its account at
  cost, with no input VAT deducted; shown apart on the invoice and in the VAT
  report.
- **Voucher** (IR Art 19): its sale is not a supply and makes no invoice; the
  supply it pays for is invoiced as usual.
- **Deemed (nominal) supply** (IR Art 15): none where no input tax was
  deducted; gifts, samples and goods to employees relieved up to SAR 200 a
  recipient a calendar year within SAR 50,000 a year; otherwise taxed on the
  value times the deducted share. A taxed one drafts a simplified invoice
  with the nominal-supply flag (KSA-2 position 4), kept and given to no one
  (Guideline for Tax Invoicing, Note 3); it counts as a standard-rated sale.
  <!-- app/Domains/Books/Services/DeemedSupplies.php -->

The VAT return draft splits its sales by where each document stands with
ZATCA (accepted, waiting, held), so the split adds up to box 6; rejected
documents are in no box and listed apart (`Reports/FilingStatus.php`).

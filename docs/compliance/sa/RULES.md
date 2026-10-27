# Saudi Arabia — compliance rules as implemented

Audience: engineers changing the Saudi module, and reviewers checking which
official rule each refusal rests on.

Sources: the E-Invoicing XML Implementation Standard v1.2 and ZATCA's KSA
schematron in its SDK (rule ids), the E-Invoicing Detailed Guidelines, the VAT
Implementing Regulations (IR), and the Guideline for Tax Invoicing and Records.
Paths are under `app/Domains/Compliance/Countries/SaudiArabia/`.

Rules are enforced in three places:

- **On the way in** — `Rules/InvoiceRules.php` (API and portal requests),
  `Rules/SellerRules.php` and `Rules/SellerIdNumber.php` (company page): a 422,
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
<!-- Rules/VatPeriodTracker.php notYetAccepted(), refusalAgainst() -->

## Seller

| Rule | Text (abridged) | Where |
|---|---|---|
| BR-KSA-39 / 40 | Seller VAT number present; 15 digits, first and last `3` | `SellerRules`, `InvoiceValidator` |
| BR-KSA-08 | Seller ID BT-29 once, scheme BT-29-1 one of `CRN, MOM, MLS, 700, SAG, OTH`, alphanumeric; with several, the first in that order. Never the VAT number | `Rules/SellerIdentification`, `SellerIdNumber`; required on a standard invoice; written by `Document/UblPartyBuilder` |
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
| BR-KSA-42 | Buyer name on a standard invoice and its notes | `InvoiceRules` |
| BR-KSA-81 | Standard invoice: buyer VAT number or another buyer ID | `InvoiceRules`, `InvoiceValidator` |
| BR-KSA-14 | Buyer ID under an allowed scheme (`fatoora.validation.buyer_id_schemes`) | `InvoiceRules`, `InvoiceValidator` |
| BR-KSA-44 / 46 | Buyer VAT 15 digits `3…3` unless export; none on an export | `InvoiceRules` |
| BR-KSA-63 / 67 | Saudi buyer: full address, 5-digit postal code | `InvoiceRules` |
| BR-KSA-25 / 49 | `VATEX-SA-EDU` / `-HEA`: buyer name and national ID (`NAT`) | `InvoiceRules`, `InvoiceValidator` |

## Header, dates, chain

| Rule | Text (abridged) | Where |
|---|---|---|
| BR-KSA-03 | UUID of letters, digits, dashes | `InvoiceValidator` |
| BR-KSA-04 | Issue date not in the future | Issue date and time are set to the moment of issue on the Kingdom's clock (Detailed Guidelines FAQ: the date "on which final Tax Invoice is generated") |
| BR-KSA-05 / 06 | Type code 388/381/383 (386 prepayment); KSA-2 `NNPNESB` | `Document/UblHeaderBuilder`, `InvoiceRules` |
| BR-KSA-07 / 31 | No self-billed export; simplified flags limited | `InvoiceRules` |
| BR-KSA-15 | Standard tax invoice carries the supply date KSA-5 | `IssuingStep::beforeNumbering()` gives one without it its issue date |
| BR-KSA-35 / 36 | Supply end date needs, and is not before, the supply date | `InvoiceRules` |
| BR-KSA-71 / 72 | Simplified summary names buyer and period | `InvoiceRules` |
| BR-KSA-92 | B2G invoice names its contract (BT-12) | `InvoiceRules` |
| BR-KSA-26 / 33 / 34 / 61 | PIH and ICV | [ICV-PIH.md](ICV-PIH.md) |
| BR-KSA-27 – 30, 60 | QR on every document; stamp structure; stamp on simplified | `Signing/` — [HASHING-AND-SIGNING.md](HASHING-AND-SIGNING.md) |

A sale time more than `fatoora.sale_time.max_ahead_seconds` ahead is refused
(`Filing/TimestampValidator::saleTimeProblem()`).

## Lines, categories, amounts

| Rule | Text (abridged) | Where |
|---|---|---|
| BR-KSA-18 | Category `S`, `Z`, `E` or `O` | `InvoiceValidator` |
| BR-KSA-23 / 24 / 69 / 83, BR-KSA-CL-04 | Reason code and text from the category's own list | `InvoiceRules`, `InvoiceValidator` |
| EN 16931 category rules | 15% standard, 0% otherwise (`fatoora.validation.allowed_tax_rates`) | `InvoiceValidator` |
| BR-KSA-11 – 13, 16 | Out-of-scope at 0%; UNTDID 4461 payment means | `InvoiceRules` |
| BR-KSA-CL-01, BR-53 | ISO 4217 currency; VAT also in SAR | `InvoiceRules`, `InvoiceValidator` |
| BR-KSA-51 – 53, 73 – 80 | Line VAT amounts; prepayment lines | `Document/UblLineBuilder`, `InvoiceRules` |
| BR-CO-10 / 14 / 15 | Totals agree | `InvoiceValidator::validateAmounts()` |
| BR-KSA-F-04 / F-06 | Amounts positive (a reduction is a credit note); field lengths | `InvoiceValidator`, `InvoiceRules` |

## Language

IR Article 66(2): invoices are issued in Arabic, other languages as
translation. The Guideline for Tax Invoicing and Records (4.2.1) approves Latin
characters for proper names, so Latin seller, buyer and product names are not
refused; the rendition's title and captions are bilingual (`Rules/LanguageRules`).

## Certificates

- An **expired** certificate cannot stamp: refused before numbering
  (`IssuingStep::assertNotExpired()`, `CERT_EXPIRED`).
- Each stamp is checked against the CA's revocation list (Security Features
  Implementation Standard 2.2.1, requirement 14; `Signing/RevocationCheck`);
  a held list carries stamping for seven days.
- A credential from another ZATCA environment is never used
  ([README.md](README.md#environments)).

## Code labels that disagree with the standard

`Rules/InvoiceValidator.php` checks correctly but prefixes some messages with
the wrong id (`Rules/InvoiceRules.php` cites correctly):

| Check | Label in code | Standard |
|---|---|---|
| Seller VAT number | BR-KSA-14 | BR-KSA-39 / 40 |
| Type code | BR-KSA-02 | BR-KSA-05 |
| ICV | BR-KSA-25 | BR-KSA-33 / 34 |
| Category S/Z/E/O | BR-KSA-40 | BR-KSA-18 |
| Exemption reason | BR-KSA-33 / 34 | BR-KSA-23, 24, 69, 83 |
| Category rate | BR-KSA-35, BR-KSA-DEC-02 | EN 16931 category rules |
| Exemption code list | BR-KSA-46 | BR-KSA-CL-04 |
| Not onboarded (warning) | BR-KSA-80 | none |

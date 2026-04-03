# UAE — FTA e-Invoicing (Peppol PINT AE) Compliance Rules Reference

**Authority:** UAE Federal Tax Authority (FTA)  
**Regulation:** Ministerial Decision No. 243 & 244 of 2025  
**XML Spec:** Peppol PINT AE (`urn:peppol:pint:billing-1@ae-1`)  
**Last updated:** 2026-04-03

---

## Scope

Applies to any person **conducting business in the UAE** for **B2B and B2G** transactions.  
**B2C is excluded** from mandatory e-invoicing until further regulatory notice.

| Scenario | In Scope |
|----------|----------|
| UAE-established company → UAE-established buyer | Yes |
| UAE-established company → UAE government body | Yes |
| UAE-established company → consumer | No (B2C) |
| Non-UAE company trading internationally | Check residency |

## XML Specification

| Field | Value |
|-------|-------|
| `CustomizationID` | `urn:peppol:pint:billing-1@ae-1` |
| `ProfileID` | `urn:peppol:bis:billing` |
| Participant ID scheme | `0235` + first 10 digits of TRN |
| XML namespace | UBL 2.1 |

**Do NOT use BIS Billing 3.0** (`urn:cen.eu:en16931:2017`) — that is the European profile.

## Business Rules (BR-AE)

| Rule ID | Rule | Engine Behaviour |
|---------|------|-----------------|
| BR-AE-01 | TRN must be exactly 15 digits | `FtaValidator.validateTrn()` rejects others |
| BR-AE-02 | Currency must be AED | `FtaValidator` throws if non-AED |
| BR-AE-03 | Document type must be 380 (invoice), 381 (credit note), or 383 (debit note) | `FtaValidator` rejects unknown types |
| BR-AE-04 | VAT rate must be 5% or 0% (zero-rated) | `FtaValidator` rejects other rates |
| BR-AE-05 | Credit/debit notes must reference original invoice | `creditNoteReference` required for types 381/383 |
| BR-AE-06 | Tax-inclusive total = tax-exclusive + VAT, within ±0.01 rounding | `FtaValidator.validateAmounts()` |
| BR-AE-07 | B2C invoices must not be submitted | Routing guard in `FtaService.submit()` |

## Rollout Timeline

| Phase | Threshold | Mandatory From |
|-------|-----------|---------------|
| Voluntary Pilot | All | 2026-07-01 |
| Phase 1 | Revenue ≥ AED 50M | **2027-01-01** |
| Phase 2 | Revenue < AED 50M | 2027-07-01 |
| Phase 3 | Federal government entities | 2027-10-01 |

## Environments

| Environment | Notes |
|-------------|-------|
| Sandbox | Available to registered participants via FTA developer portal |
| Production | UAE FTA Access Point API endpoint |

Set via `FTA_ENV=sandbox|production` in `.env`.

## Known Limitations

- FTA submissions are **asynchronous** — the API returns a submission reference; status must be polled via `checkStatus`.
- Peppol participant ID registration must be completed with a UAE FTA Access Point Service Provider (ASP) before production submission.
- B2C invoices are excluded — the `FtaService` skips submission for `simplified` invoice types.

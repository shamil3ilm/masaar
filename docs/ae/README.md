# UAE — FTA e-Invoicing (Peppol PINT AE)

## Regulatory Authority
UAE Federal Tax Authority (FTA)  
Official portal: https://uaeei.tax.gov.ae  
Guidelines: UAE Electronic Invoicing Guidelines v1.0 (Feb 2026)

## Legal Basis
Ministerial Decision No. 243 of 2025 (Electronic Invoicing System)  
Ministerial Decision No. 244 of 2025 (Implementation)

## Scope Trigger
Any person **conducting business in the UAE** for **B2B and B2G** transactions.  
**B2C is excluded** until further regulatory notice.  
Scope is activity-based, not solely establishment-based — UAE TRN holders conducting UAE transactions are in scope.

## XML Standard
**Peppol PINT AE** — UAE national Peppol profile.  
NOT BIS Billing 3.0 (that is the European profile).

| Field | Value |
|-------|-------|
| `CustomizationID` | `urn:peppol:pint:billing-1@ae-1` |
| `ProfileID` | `urn:peppol:bis:billing` |
| Participant ID scheme | `0235` + first 10 digits of TRN |

## Invoice Types
| Document Type | Code |
|--------------|------|
| Invoice | 380 |
| Credit Note | 381 |
| Debit Note | 383 |

## VAT Rates
| Rate | Description |
|------|-------------|
| 5% (0.05) | Standard UAE VAT rate |
| 0% (0.00) | Zero-rated supplies |

## Mandatory Rollout Timeline
| Phase | Applies to | Mandatory Date |
|-------|-----------|----------------|
| Pilot (voluntary) | All | 2026-07-01 |
| Phase 1 | Revenue ≥ AED 50M | **2027-01-01** |
| Phase 2 | Revenue < AED 50M | 2027-07-01 |
| Phase 3 | Federal government | 2027-10-01 |

## API Endpoints
| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/compliance/ae/submit/{invoiceId}` | Submit to UAE FTA |
| GET | `/api/compliance/ae/status/{submissionId}` | Check submission status |
| POST | `/api/compliance/ae/retry/{submissionId}` | Retry failed submission |
| GET | `/api/compliance/ae/submissions` | List submissions |

## Known Limitations
- B2C invoices are out of scope (FTA mandate, not a platform limitation)
- UAE FTA sandbox API credentials required separately from KSA credentials
- No offline queue implemented yet (Phase 2 of platform development)

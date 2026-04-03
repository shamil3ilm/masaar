# Saudi Arabia — Fatoora (ZATCA Phase 2)

## Regulatory Authority
ZATCA — Zakat, Tax and Customs Authority  
Official portal: https://fatoora.zatca.gov.sa

## Scope Trigger
Applies to **KSA-resident taxable persons** (fixed establishment or registered place of business in KSA).  
Non-resident companies with a KSA VAT number but no KSA establishment are **exempt**.

## XML Standard
UBL 2.1 with XAdES-BES digital signatures (ECDSA secp256k1).  
QR code: TLV-encoded, 9 tags (Phase 2).

## Invoice Types
| Type | Description | Flow |
|------|-------------|------|
| Standard (B2B) | Taxable invoice ≥ SAR 1,000 | Clearance — ZATCA stamps before delivery |
| Simplified (B2C) | Consumer invoice | Reporting — submit within 24h |
| Credit Note | Cancel/adjust standard | Clearance |
| Debit Note | Upward adjustment | Clearance |

## Mandatory Rollout (Integration Phase)
Waves are released by taxable revenue threshold. ZATCA notifies each wave 6 months in advance.  
Wave 24 (SAR 375K threshold) deadline: **2026-06-30**.

## Onboarding Flow
1. Generate CSR (`fatoora:generate-csr`)
2. Request Compliance CSID (CCSID) with OTP from Fatoora portal
3. Run compliance check
4. Request Production CSID (PCSID)
5. All production invoices signed with PCSID private key

## Environment URLs
| Environment | Base URL |
|-------------|----------|
| Sandbox | `https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal` |
| Simulation | `https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation` |
| Production | `https://gw-fatoora.zatca.gov.sa/e-invoicing/core` |

## Artisan Commands
| Command | Description |
|---------|-------------|
| `fatoora:generate-csr` | Generate CSR for onboarding |
| `fatoora:onboard` | Full onboarding wizard (CCSID → PCSID) |
| `fatoora:sandbox-test` | Submit test invoice to sandbox |
| `fatoora:validate` | Validate invoices against BR-KSA-* rules |
| `fatoora:check-certificate` | Check certificate expiry |
| `fatoora:verify-hash-chain` | Verify ICV hash chain integrity |

## API Endpoints
| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/compliance/sa/generate/{invoiceId}` | Generate hash + QR |
| POST | `/api/compliance/sa/validate/{invoiceId}` | Validate without submitting |
| POST | `/api/compliance/sa/submit/{invoiceId}` | Submit to ZATCA |
| GET | `/api/compliance/sa/status/{submissionId}` | Check submission status |
| POST | `/api/compliance/sa/onboard` | Start CSID onboarding |

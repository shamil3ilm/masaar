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
Waves are released by taxable revenue threshold. ZATCA notifies each wave 6 months in advance;
the Fatoora portal lists the current waves and their deadlines.

## Onboarding Flow
1. Generate CSR (`fatoora:generate-csr`)
2. Request Compliance CSID (CCSID) with OTP from Fatoora portal
3. Run compliance check
4. Request Production CSID (PCSID)
5. All production invoices signed with PCSID private key

The sandbox accepts the fixed OTP `123345`, so steps 1-4 need no taxpayer.
Simulation and production need an OTP from the Fatoora portal, which is issued
only against TIN credentials. See [STATUS.md](STATUS.md) for what has actually
been run and what has not.

## Environment URLs
| Environment | Base URL |
|-------------|----------|
| Sandbox | `https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal` |
| Simulation | `https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation` |
| Production | `https://gw-fatoora.zatca.gov.sa/e-invoicing/core` |

## Switching Saudi Arabia on and off
Saudi Arabia has the same switch as the UAE. A platform admin turns it on or
off on the admin console's **Countries** page (`/admin/countries`, permission
`platform.configure`), and that switch wins over `ZATCA_ENABLED`, which is
only the default (on) until someone throws it. Each switch is stored with who
threw it and when, and is on the audit record.

While Saudi Arabia is off:

- nothing new is issued there. The portal says so on the issue button and in
  a banner on every page. `POST /api/compliance/sa/generate`, `validate` and
  `submit`, and the pipeline, answer `422 JURISDICTION_UNAVAILABLE`;
- sign-up and the company country list do not offer it;
- platform billing stops too, if the billing organization
  (`BILLING_ORGANIZATION_ID`) is Saudi. The page warns before switching;
- what was already issued is not stranded. The offline queue is still
  replayed, submissions are still recovered and retried, and the status
  route and portal screens stay open.

## Artisan Commands
| Command | Description |
|---------|-------------|
| `fatoora:generate-csr` | Generate CSR for onboarding |
| `fatoora:onboard` | Full onboarding wizard (CCSID → PCSID) |
| `fatoora:onboard --step=submit` | Clear and report the six documents with the production certificate |
| `fatoora:sandbox-test` | Prints the sandbox endpoints and walks the steps. Its `--step=report` submits nothing; use `fatoora:onboard --step=submit` |
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

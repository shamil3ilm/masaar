# Saudi Arabia — Fatoora Compliance Rules Reference

**Authority:** ZATCA  
**Regulation:** E-Invoicing Regulation, Article 3  
**Last updated:** 2026-04-03

---

## Invoice Types

| Code | Type | Flow | Notes |
|------|------|------|-------|
| `standard` | B2B / Standard Tax Invoice | **Clearance** — ZATCA stamps before delivery to buyer | Required for transactions ≥ SAR 1,000 with VAT-registered buyers |
| `simplified` | B2C / Simplified Tax Invoice | **Reporting** — submit within 24 hours of issuance | For consumer sales |
| `credit_note` | Credit Note | Clearance | Must reference original invoice UUID |
| `debit_note` | Debit Note | Clearance | Must reference original invoice UUID |

## Business Rules (BR-KSA)

| Rule ID | Rule | Engine Behaviour |
|---------|------|-----------------|
| BR-KSA-01 | VAT number must be 15 digits, start and end with `3` | `Organization.isValidVatNumber()` rejects others |
| BR-KSA-02 | Invoice UUID (BT-124) must be unique per organization | `InvoiceHasher` generates; duplicates rejected by ZATCA |
| BR-KSA-03 | ICV (Invoice Counter Value) must be sequential, no gaps | `HashChain` service tracks ICV per branch |
| BR-KSA-04 | Previous invoice hash (PIH) must be included in XML | Linked to prior invoice hash; first invoice uses fixed seed |
| BR-KSA-05 | Standard invoices require clearance before delivery | `FatooraSubmissionService` enforces `submit → CLEARED` before status changes to `issued` |
| BR-KSA-06 | QR code (Phase 2) must encode 9 TLV tags | `QrCodeGenerator` enforces 9-tag structure |
| BR-KSA-07 | Supply date must be present for deferred supply | `issue_date` vs `supply_date` both stored on `Invoice` |
| BR-KSA-08 | Credit/debit notes must have billing reference | Validated in `FatooraComplianceService` |

## Rollout Waves

| Wave | Revenue Threshold | Deadline |
|------|-------------------|---------|
| 1 | ≥ SAR 3 billion | 2023-01-01 |
| … | … | … |
| 24 | ≥ SAR 375,000 | **2026-06-30** |

Wave is tracked in `ComplianceProfile.settings['wave']` for reporting purposes.

## Environments

| Environment | Base URL | Purpose |
|-------------|----------|---------|
| Sandbox | `https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal` | Development + testing |
| Simulation | `https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation` | Pre-production compliance check |
| Production | `https://gw-fatoora.zatca.gov.sa/e-invoicing/core` | Live submissions |

Set via `FATOORA_ENV=sandbox|simulation|production` in `.env`.

## Known Limitations

- Clearance is synchronous — the API blocks until ZATCA stamps or rejects the invoice.
- CSID certificates expire. Use `fatoora:renew-certificate` before expiry.
- Offline mode queues submissions locally when ZATCA is unreachable. Queued invoices are processed by the `ProcessFatooraSubmission` job.

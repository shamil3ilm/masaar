# Compliance policies

Audience: auditors and compliance reviewers, and the engineers who keep the
code to these decisions.

The decisions the platform takes where the authorities' texts leave a
choice, and what the code does about each. Where something is not built, it
says so. Country rules are in [sa/RULES.md](sa/RULES.md) and
[ae/RULES.md](ae/RULES.md); document states in
[FILING-STATES.md](../architecture/FILING-STATES.md).

## 1. Rules at the time of issue

An invoice is judged by the rules in force when it was issued. An issued
invoice is never edited, cancelled, re-signed or re-submitted because a rule
changed; a correction is a credit or debit note (E-Invoicing Detailed
Guidelines p63: "Invoices cannot be cancelled"). The `invoices` columns
`rule_version`, `schema_version`, `signature_algorithm` and `hash_algorithm`
exist but **nothing writes them yet**.
<!-- database/migrations/0080_invoices.php; no writer in app/ -->

## 2. Invoice identity

| Field | Meaning | Unique |
|---|---|---|
| `id` (UUID) | Identity; use it for references | Globally |
| `invoice_number` | The platform's number, from the organization's sequence | Per organization |
| `erp_reference_id` | The caller's own reference | Indexed only |
| `icv` | ZATCA's counter (Saudi) | Per device, not per organization ([sa/ICV-PIH.md](sa/ICV-PIH.md)) |

<!-- database/migrations/0230_unique_indexes.php; 0270_device_chains.php -->

## 3. Copies of an invoice that is not a tax invoice

A document that is not a valid tax invoice is never handed over as one. Each
country's `Presentation::assertShareable()` guards every download, print and
email: Saudi Arabia needs it issued, with a QR, not rejected and - if
standard - cleared; the UAE needs it issued and not rejected. Watermarked
"draft" or "audit" exports are **not built**.

## 4. Organization lifecycle

| Status | New invoices and certificates | Filing what it already issued |
|---|---|---|
| `pending_review` | No (drafts only) | - |
| `active` | Yes | Yes |
| `suspended` | No | Yes: filing is the taxpayer's obligation |
| `rejected` | No | No |

<!-- app/Domains/Organization/Services/FilingClearance.php assertCleared(), assertMayFile() -->

A merger or VAT change registers a new organization with a new chain; the
old one's invoices stay. One VAT number belongs to one organization, and the
Saudi VAT number is fixed once onboarded. No `legally_replaced` or
`archived` status exists. An account frozen for non-payment keeps filing
and corrections ([ACCESS.md](../architecture/ACCESS.md)).

## 5. Cryptographic parameters

A Saudi document records its algorithms in its own signature: ECDSA on
secp256k1 with SHA-256, SHA-256 digests, C14N 1.1
(`http://www.w3.org/2006/12/xml-c14n11`), and the certificate embedded with
its version in `device_certificates`. They are constants, not
configuration; if ZATCA changes them, issued documents keep their own and
are never re-signed. Detail: [sa/HASHING-AND-SIGNING.md](sa/HASHING-AND-SIGNING.md).
<!-- app/Domains/Compliance/Countries/SaudiArabia/Signing/XadesSigner.php, XadesDigest.php -->

## 6. Legal hold

Compliance records - invoices, notes, the chain, submissions, Peppol
documents, payments, audit logs - have no `deleted_at` and nothing deletes
them (`TableTimestampsTest`; `DocumentRetainedException`), so no hold is
needed to preserve them. A per-organization hold is **not built**: the
`organizations` columns `hold_ref`, `legal_hold_at` and `hold_expires_at` are
unused. Until it is, a hold means: do not run the optional partition archive
(section 11).

## 7. Timestamp Authority

**An invoice is dated when the platform generates it: issue date, issue time
and QR timestamp are the system clock at issuance, on the Kingdom's clock. A
till's sale time (`sold_at`; `issued_at` is its old name) is the supply,
never the issue time, and a sale is never refused for being late. No
timestamp authority is used: ZATCA requires XAdES baseline B-B, which
carries no signature timestamp.**

| Rule | Detailed Guidelines | What it says |
|---|---|---|
| Issue date | p60 | "Invoice Issue Date must be the date on which final Tax Invoice is generated." |
| Reporting deadline | p12, p18, p32 | Simplified invoices are reported "within (24) hours from its issuance" |
| Not in the future | p55 | BT-2 "must be less or equal to the current date" (BR-KSA-04) |
| Keep trying | p46, p47, p49 | Retry "in regular intervals until successful" (~every 15 minutes) and "keep evidence" |
| Past 24 hours | p48 | Notify ZATCA through its form, report immediately, notify again once reported |

The supply date is KSA-5 (`cac:Delivery/cbc:ActualDeliveryDate`), required
on a standard tax invoice by BR-KSA-15. A sale time more than
`ZATCA_SALE_TIME_MAX_AHEAD_SECONDS` (30) ahead of the clock is refused
before a counter is taken; any past sale is issued. Filing validates the
invoice's timestamp with a 30-second drift tolerance (`TimestampValidator`).

Late reporting: the offline queue retries up to every
`ZATCA_OFFLINE_RETRY_INTERVAL` (900 s) without giving up on a retryable
failure, each attempt a `reporting_attempts` row; past
`ZATCA_REPORTING_DEADLINE_HOURS` (24) the hourly
`fatoora:overdue-submissions --alert` tells administrators to notify ZATCA,
and they record each notification on the Late reporting page (audited).

## 8. Certificate versions and renewal

**A device signs new documents with its active production certificate only,
and files every document with the certificate that signed it.**

Each certificate a device held is a row of `device_certificates`
(`version`, `certificate_id` = SHA-256 of the certificate, `serial_number`,
`issuer`, `valid_from`, `valid_to`, `status` `active` | `superseded` |
`revoked`, `revoked_by`, `revoked_reason`); secrets stay in the encrypted
`CredentialStore`, and versions are never deleted. Every filing path uses
`CredentialStore::forInvoice()`, the version the document's chain entry
names.

| Signing version | Filed? |
|---|---|
| `active`, `superseded` | Yes, with its own CSID and secret |
| expired | Yes; but `SubmissionGuard` refuses `CERT_EXPIRED` while the organization's current production certificate is expired |
| `revoked` | No: `CERT_REVOKED` before anything is sent |

- **An expired certificate cannot stamp.** Issuing refuses `CERT_EXPIRED`
  before the counter is spent (`IssuingStep::assertNotExpired()`);
  `fatoora:check-certificate --notify` warns daily beforehand.
- **Revocation before stamping** (Security Features Implementation Standard
  v1.2, 2.2.1 req. 14): checked against the certificate's CRLs (or
  `ZATCA_CRL_URLS`, verified with `ZATCA_CA_CHAIN_PATH`), a list held up to
  seven days, then `CERT_REVOCATION_UNVERIFIED`; `fatoora:check-revocation`
  runs daily. An admin may mark a version revoked (`certificates.revoke`).
  The check passes when no list is configured (the certificate names none
  and `ZATCA_CRL_URLS` is empty) and is skipped while
  `ZATCA_FEATURE_CRL_CHECK` is off; the readiness report and the
  certificates page say when no list is configured.
- **A 401 pauses the device** (Detailed Guidelines: "Check authentication
  certificate and secret and resubmit"): `FilingPause` stops sending, new
  documents wait offline; renewal resumes it (`FilingResume`).
- **Renewal** (portal, head office or any till, one OTP;
  `CertificateRenewal`) is refused while the current certificate's documents
  are unfiled (`RenewalGuard`; Security Features standard 2.1.2), unless an
  administrator gives an audited reason of ten characters or more.

A document is always filed with the certificate that signed it, never
re-signed: re-signing would change its hash and break the chain.

## 9. ZATCA environments

Each Saudi organization works in one ZATCA environment, and only a platform
admin moves it. **A move is refused while anything signed in the current
environment is unfiled** (`UnfiledDocuments`); one that goes through archives
the credentials, never deletes them, and is audited. **A move to or from
production is maker-checker**: it waits for a second platform admin's
approval (`zatca_environment.production`, held as shipped), unless no other
approver exists and bypass is allowed, which is recorded as
`approval.bypassed` and emailed to the owner. The environments and the
switch are described in [sa/README.md](sa/README.md#environments).
<!-- app/Domains/Compliance/Countries/SaudiArabia/Rules/FatooraEnvironment.php; Onboarding/EnvironmentSwitch.php; Onboarding/EnvironmentSwitchActions.php -->

## 10. Webhook replay protection

Every delivery is a signed envelope (`id`, `event`, `timestamp`, `data`)
with headers `X-Webhook-ID`, `X-Webhook-Event`, `X-Webhook-Timestamp` and
`X-Webhook-Signature` (`sha256=` + HMAC-SHA256 of the raw body). A delivery
unanswered (connection failure, timeout, 408, 429, 5xx) is retried four
more times over about forty minutes with the same `id`; any other non-2xx
is final. Ten failures in a row with nothing delivered for 24 hours disable
the endpoint. Receivers verify the signature, discard repeated `id`s and
reject a stale `timestamp`: see [WEBHOOKS.md](../integration/WEBHOOKS.md).
<!-- app/Domains/Webhook/Jobs/DeliverWebhook.php; DTOs/DeliveryOutcome.php; Services/WebhookService.php -->

## 11. Retention

| Country | Minimum | Source |
|---|---|---|
| Saudi Arabia | Six years from the end of the tax period; capital assets their adjustment period plus five years; real estate fifteen years in all cases; e-invoices as the e-invoicing regulations say | VAT Implementing Regulations, Article 66 (Records), paragraphs 1 and 3(h); Guideline for Tax Invoicing and Records 8.3.3 |
| United Arab Emirates | Five years after the tax period; real estate records seven years from the end of the calendar year the document was created; four more in a dispute or audit, one more after a fifth-year voluntary disclosure; e-invoices retrievable for the authority | Tax Procedures Executive Regulation Art 3(1), per Electronic Invoicing Guidelines v1.1 §5.4; Ministerial Decision No. 243 of 2025, Art 11 |

The database deletes no compliance record, so it meets both indefinitely.
`compliance:partition-maintenance` runs monthly to create partitions; its
84-month archive is optional and not scheduled. Yearly backups are kept six
years and never pruned to fit a size (`config/backup.php`): shorter than the
fifteen-year Saudi and seven-year UAE real estate periods, so an
organization with real estate records needs a longer backup plan. Only
operational data expires: webhook logs (90 days), the API request log
(`integrations.request_log_days`) and processed offline-queue items (7 days).

## 12. Out of scope

Tax treatment, the truth of ERP data, legal advice and accounting decisions
are the customer's, as are exemption evidence and answering the authorities.

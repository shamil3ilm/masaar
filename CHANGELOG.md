# Changelog

All notable changes to Masaar will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed — may need a change on your side

- **An invoice sent without `invoice_number` is numbered when it is issued**,
  from your organization's sequence for its document type (by default
  `INV-2026-00001`, `CN-…`, `DN-…`). The pipeline used to make one up as
  `INV-{erp_reference_id}` or `INV-{timestamp}`. A draft created without a
  number has `invoice_number: null` until it is issued. Sending your own
  number works as before.
- **Deployments file with ZATCA only unless `UAE_FTA_ENABLED=true`.** While it
  is off, `/api/compliance/ae/*` answers 404 and organizations register in
  Saudi Arabia only.
- **Production refuses to serve until it is configured**: `APP_URL` off
  localhost, `JWT_SECRET`, `API_KEY_PEPPER`, a real `MAIL_MAILER` and
  `MAIL_FROM_ADDRESS`. An unrecognised `ZATCA_ENVIRONMENT` is refused instead
  of falling back to the sandbox.
- **Credit and debit notes must give a reason.** `adjustment_reason` is now
  required with `document_type` `credit_note` or `debit_note`, as ZATCA's
  BR-KSA-17 requires. A note without one is refused with 422 before it is
  signed, instead of being refused by ZATCA afterwards.
- **`billing_ref` names the original invoice in your organization**, by its
  invoice number or its id. A reference to a draft is refused.
- **UAE submission status codes say what happened.** Every outcome used to
  answer 201. Now: 201 submitted, 202 under review by the FTA, 422 rejected
  (`error.details.errors` lists why), 502 the FTA could not be reached (kept
  and retried; `error.details.next_retry_at` says when). Do not resend on 502.
- **The licence admin API uses the standard envelope**: `success`, then `data`
  or `error` with `code`, `message` and `details`. Field errors moved from
  `errors` to `error.details`.
- **Webhooks are retried.** A delivery your endpoint does not answer (timeout,
  connection failure, 408, 429 or 5xx) is retried for about forty minutes.
  Every retry carries the same `id` and `X-Webhook-ID`, so discard repeats by
  that id. Any other non-2xx answer is final and not retried. An endpoint is
  disabled only after ten failed deliveries in a row with nothing delivered
  for 24 hours.
- **Sign-in is limited per account as well as per address**, on the API as on
  the console. A locked sign-in answers 429 with `Retry-After`.
- **Platform admins enrol two-factor with a one-time link** from another
  admin, or from `masaar:enrolment-link` on the server.

### Added

- **Invoice numbering you configure**: a pattern per document type (and per
  branch) with `{YYYY}`, `{YY}`, `{MM}` and `{SEQ}`, zero-padding, and a yearly,
  monthly or no reset. `GET /api/settings/numbering`,
  `PUT /api/settings/numbering/{documentType}`, and a Numbering page in the
  portal. Numbers are given at issue, so issued invoices run without gaps.
- **The seller details ZATCA requires can be set**: CR number and national
  address on `POST`/`PUT /api/organizations`, and a Company page in the
  portal. Checked in ZATCA's shapes; the VAT number is fixed once onboarded.
- **VAT-inclusive prices.** `prices_include_vat` on an invoice, or as the
  organization's default, says `unit_price` and `discount_amount` include VAT.
  The customer's total is kept exactly and the net is derived from it.
- **`certificate.expiring` webhook**, sent as a certificate nears expiry.
- **Portal issuing and printing**, with the QR drawn on the printed copy.

### Fixed

- A ZATCA outage, throttle or gateway error is retried instead of being left
  unfiled. A standard invoice that may already have reached ZATCA is held for
  reconciliation rather than cleared twice.
- An invoice that breaks ZATCA's rules is refused before it is given a
  counter, so a refusal leaves no gap in the chain.

## [1.0.0]

### Added

- **Core Features**
  - ZATCA Phase 2 compliant e-invoicing API
  - UBL 2.1 XML generation with all required elements
  - XAdES-BES digital signatures (ECDSA secp256k1)
  - TLV-encoded QR codes (9 tags for Phase 2)
  - Multi-tenant architecture with organization isolation
  - JWT and API key authentication
  - Webhook notifications with HMAC-SHA256 signatures
  - ZATCA business rules validation (BR-KSA-*)

- **ZATCA Integration**
  - CSR generation for onboarding
  - Compliance CSID (CCSID) request flow
  - Production CSID (PCSID) request flow
  - Invoice clearance (B2B) support
  - Invoice reporting (B2C) support
  - Credit and debit note support

- **Infrastructure**
  - Production-ready Dockerfile (PHP 8.2-FPM Alpine)
  - Docker Compose for local and production deployment
  - GitHub Actions CI/CD for automated Docker builds
  - Prometheus metrics endpoint (`/api/metrics`)
  - Grafana dashboard configuration
  - K6 load testing scripts

- **SDKs**
  - Python SDK
  - TypeScript/JavaScript SDK
  - PHP Legacy SDK (Laravel 8+)
  - Java SDK
  - Go SDK
  - Ruby SDK
  - .NET SDK
  - Kotlin SDK
  - Dart/Flutter SDK
  - Swift SDK
  - Rust SDK

- **Admin Features**
  - Web-based admin dashboard
  - Organization management
  - Queue monitoring
  - Audit logging

- **Documentation**
  - OpenAPI 3.0 specification
  - Deployment guides
  - Security policy
  - Contributing guidelines

### Security

- Input validation and sanitization
- SQL injection prevention (parameterized queries)
- XSS prevention with output encoding
- CSRF protection
- Rate limiting on all endpoints
- Audit logging for compliance actions
- Private key encryption at rest

---

## Version History

| Version | Status |
|---------|--------|
| 1.0.0   | Current |

[Unreleased]: https://github.com/shamil3ilm/masaar/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/shamil3ilm/masaar/releases/tag/v1.0.0

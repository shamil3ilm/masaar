# Changelog

Audience: integrators, operators and anyone upgrading a deployment.

All notable changes to the platform are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Removed

- **The client SDKs.** They covered at most 21 of the API's 122 operations
  and none read the API's error envelope, so they lost the error code and
  whether a failed filing was safe to retry. Generate a client from
  `docs/openapi.yaml` instead; webhook verification for six languages is in
  `docs/integration/WEBHOOKS.md`.

### Changed — may need a change on your side

- **Console commands moved to the `platform:` namespace**, for example
  `platform:create-admin` and `platform:enrolment-link`. Update scripts, cron
  entries and runbooks that call the old names.
- **Production needs a dedicated signing-credential key**
  (`ZATCA_CREDENTIAL_KEY`). Signing no longer falls back to the application key.
- **An `Idempotency-Key` reused with a different body is refused** with 422
  `IDEMPOTENCY_KEY_REUSED`. The same key with the same body still replays.
- **One set of idempotency codes.** `IDEMPOTENCY_KEY_REUSED` (422) and
  `IDEMPOTENCY_KEY_IN_PROGRESS` (409), with `category` `idempotency`, on
  every path. The Saudi filing path answered `IDEM_003` (400) and `IDEM_004`
  for the same failures; those codes are gone. No integrator was live on
  them, so there is no alias.
- **Switching organization revokes the token the request came with.** Use the
  token the switch returns.
- **An unauthenticated API request answers 401** in the standard error
  envelope, and a rate-limited one 429.
- **A Saudi invoice carries Arabic for every free text it prints**: names,
  notes, a note's reason, payment terms and charge reasons. An organization can
  be given a grace period from the console.
- **A Saudi organization needs its seller identification** (commercial
  registration or another allowed scheme) before onboarding or issuing a
  standard invoice. The VAT number is no longer used as the seller identification.
- **A credit or debit note is issued only after ZATCA has accepted its
  original invoice.** Until then it stays a draft and names the original it waits for.
- **Each organization has its own ZATCA environment**, set by a platform
  admin. A switch archives its credentials and it onboards again; the switch is
  refused while documents signed in the current environment are unfiled.
- **A UAE company's branches name their emirate.**
- **Countries are switched on and off from the console.** While a country is
  off, new issuing there is refused (422 `JURISDICTION_UNAVAILABLE`); documents
  already issued are still filed.
- **A feature switched off for an organization is refused on every issuing
  path** (403 `AUTH_014`).
- **Licences carry the environment they file in.**
- **A credit or debit note counts in its own VAT period**, never in its
  original's, even while the original's return is not yet due.
- **The submission job never resends a rejected document.** A rejection is
  remedied with a corrected invoice, as on every other path.
- **`GET /api/v1/invoices` withholds an uncleared Saudi standard invoice's
  QR and signed XML**, as the pipeline already did: `qr_code` and
  `signed_xml` are `null` until ZATCA clears it, on one invoice, the list, the
  sync walk (also under `/api`) and `/compliance/sa/status`. Read them again
  once the invoice is cleared.
- **A Saudi summary invoice counts in the VAT return of the month it
  covers**, not the month it is issued in: October's deliveries invoiced on
  10 November are October's tax (VAT Implementing Regulations Art 23). A
  return already filed for that month shows the summary as a change since it
  was filed.
- **Approving a company no longer shows a licence secret.** The company makes
  its own API keys under Integrations.

### Added — by area

#### Integrators

- **An `Idempotency-Key` on the money writes**: selling or using a voucher,
  making a contract, invoicing a stage, releasing retention, recording a
  delivery note and drafting a summary invoice, under `/api/v1` and `/api`.
  A retry under the same key answers with the first request's answer and
  `Idempotent-Replayed: true`; nothing is sold, paid, released or drafted
  twice.
- **`/api/v1/pipeline/submit` and `/pipeline/split` say when they replay**:
  a retry answered from what the first call made carries
  `Idempotent-Replayed: true`, as every other idempotent route does, with
  the status the invoice's state earns now.
- **A request under an `Idempotency-Key` that fails saves nothing.** The
  routes the key middleware guards run in one transaction, so a refusal, a
  `503 SYS_008`, an error or a process that died leaves nothing a retry
  could repeat. A key whose request died answers 409 for five minutes, not
  for the key's 30 days. Settling, adjustments and a schedule's period run
  again file as they go; one that failed after saving part answers the new
  `409 IDEMPOTENCY_KEY_INCOMPLETE` instead of running again.
- **Webhook secret rotation without a gap.** For 24 hours after a rotation
  each delivery carries two signatures in `X-Webhook-Signature`
  (`sha256=<new>,sha256=<old>`), so a receiver holding either secret
  verifies it; the answer gives `previous_valid_until`.
  `{"revoke_previous": true}` (and a portal option) ends a leaked secret at
  once. A verifier that compared the whole header must now accept any one
  signature in it, as the samples in `docs/integration/WEBHOOKS.md` do.
- **The webhook verification samples check freshness**: a delivery whose
  signed `timestamp` is more than five minutes off is refused, as well as one
  whose HMAC does not match (`WEBHOOKS.md` and the `/developers` page).
- **The OpenAPI description names the error codes and the busy answer**: an
  `ErrorCode` enum generated from the platform's list of compliance and
  idempotency codes, `503 SYS_008` with `Retry-After` on every write, and
  `409 VAL_026` on `/pipeline/submit` and `POST /invoices`. The drift test
  now checks these, not only the routes.
- **A deprecation and sunset policy** (`docs/integration/API.md`): a route on
  its way out is announced in this file and in `docs/openapi.yaml`, and its
  answers carry `Deprecation`, `Sunset` and a successor `Link`, six months at
  least before it stops. No route is deprecated today.
- **`503 SYS_008` says "nothing was saved" only where that holds**: on a
  route run in one transaction. Elsewhere it says what was saved before is
  kept.

#### Saudi Arabia (ZATCA)

- Several devices (tills) per company, each with its own OTP onboarding,
  certificate, counter, numbering series and printer. A till can be renewed or
  retired.
- Device certificates page and certificate renewal in the portal. Revoked
  certificates are found daily and stop signing.
- A device whose credentials ZATCA refuses pauses its filing until renewed;
  expiry is warned per till.
- Government buyers, exports, late reporting evidence, all allowed seller
  identification schemes, supply dates and line item codes.
- The authority's warnings are shown in the portal and the API; a rejected
  invoice names each rule it broke.
- Commands to rehearse a pilot against the sandbox and check readiness before
  a switch to simulation (`platform:rehearse`, `platform:simulation-preflight`).

#### UAE (Peppol)

- Portal screens for UAE e-invoicing and a country section in sign-up.
- An e-invoicing start date per organization. Before it, invoices are issued
  and kept but not sent. Reminders 90, 30 and 7 days before it.
- Service-provider appointment on the company page; issuing is refused after
  the start date until one is recorded.
- Delivery to the buyer and the report to the authority tracked separately,
  with timed retries through provider outages.
- Received invoices, a service-provider simulator and a console page for the network.

#### Invoicing and portal

- Invoice templates with a live preview, and a choice of the language printed first.
- VAT treatment per line, product and customer, with business-type presets.
- Billing schedules and instalment plans; sales split between a patient and an insurer.
- CSV import of invoices, customers and products, checked before saving.
- Edit and delete drafts; archive a used customer or product.
- Credit and debit notes can need a second person's approval (off by default).
- Activity record, branches, a daily close per device, and saved list views.
- Returns, exchanges and replacements from one action on an issued invoice
  (`POST /invoices/{invoice}/adjustments`): the notes and any new draft
  invoice are previewed first, and the original never changes.
- Delivery notes, and a customer's month drafted as one summary tax invoice.
- Contracts billed in stages, with retention withheld and released on its own
  invoice, and a statement per contract.
- Disbursement lines in Saudi Arabia, out of scope and printed apart; refused
  in the UAE.
- ZATCA rule codes explained in plain English and Arabic on warnings and
  rejections. A note is refused before its original is accepted.

#### Books

- A Books area: suppliers and purchases with input VAT, counted in the period received.
- A box-by-box VAT return draft for Saudi Arabia and the UAE (VAT 201). UAE
  input tax follows the pay-or-intend-to-pay rule.
- Receivables as at any date, customer statements and sales reports.
- Accountants record in Books; viewers read.
- Vouchers, sold with no invoice and no VAT and used to pay an issued invoice.
- Deemed supplies, decided by each country's thresholds; a taxed one drafts
  the invoice that states its output tax.
- Bad-debt relief on unpaid sales, and the Saudi input VAT reversed on
  purchases still unpaid 12 months after supply.
- The Saudi VAT return and sales report split sales by ZATCA filing status
  and list rejected documents apart.
- Customer refunds of a credit balance, and money received on account that
  later pays invoices or is paid back, on the statement and in receivables.
- A credit note on several invoices is spread over them in receivables, so
  bad-debt relief and the payment cap read what is left after it.
- Reversing a customer payment undoes the bad-debt recovery and voucher use it
  made; a drafted Saudi recovery invoice is discarded, an issued one flagged
  for a credit note.
- Supplier credit notes, and UAE sales credit notes, are capped against the
  invoices they correct.
- Filing a VAT period checks it is a month or a quarter with no gap; the
  return flags unissued deemed-supply invoices and overdue retention; UAE sales
  keep the emirate they were issued from.
- Receivables read one invoice's balance in a fixed number of queries, and the
  open list leaves till sales out, for supermarket and pharmacy volumes.

#### Admin console

- Admin invitations, custom admin roles, one platform owner handed on by
  transfer, and owner recovery from the server.
- Organization suspension, per-organization feature switches with a reason,
  and a Countries page.
- Approval policies for admin actions, with a bypass when no other approver exists.

#### Platform finance and billing

- Platform invoices, payments, credit, refunds and write-offs, in the
  customer's currency, with statements and ageing.
- Bank statement and card settlement import with matching to payments.
- Expenses, recurring costs, suppliers and payables.
- Finance and expense reports, including the VAT return draft and profit and loss.
- A journal, trial balance and cash-by-bank-account export for the platform's
  own books (billing.manage), and a revenue deferral report by month.
- Write-offs split into amount before VAT and VAT in the profit and loss, with
  bad-debt relief on the platform's own invoices in its VAT view.
- Payment reminders, and an overdue-payment freeze (off by default).
- A referral programme (off by default).

#### Integrations

- Organization admins make and revoke their own API keys and read a request log.
- Webhooks managed in the portal, with test events, secret rotation and deliveries.
- A public developer page built from the API description.

#### Website

- Editable, orderable home page sections; testimonials off by default.
- A contact form with an inbox in the console, and search and sharing text in both languages.

#### Security, accessibility and operations

- Webhook signing secrets encrypted at rest; webhooks cannot target internal addresses.
- The private disk, which holds signing credentials, is no longer served over HTTP.
- A web request with no organization reads no tenant's records.
- One confirmation pattern for consequential actions, form errors under each
  field, a Hijri date beside the calendar for Saudi organizations, and
  branded bilingual error pages.
- Conformance jobs fail unless their suites really ran.

#### Documentation

- Documentation reorganised by audience; start at `docs/README.md`.
- User guides for the portal, Books, the UAE, the admin console and end-to-end workflows.

- **An invoice sent without `invoice_number` is numbered when it is issued**,
  from your organization's sequence for its document type (by default
  `INV-2026-00001`, `CN-…`, `DN-…`). The pipeline used to make one up as
  `INV-{erp_reference_id}` or `INV-{timestamp}`. A draft created without a
  number has `invoice_number: null` until it is issued. Sending your own
  number works as before.
- **Organizations created through `POST /api/organizations` start pending
  review**, as sign-ups do. Until a platform admin approves one and assigns
  its plan, ZATCA onboarding and issuing answer 403 with `AUTH_011` (under
  review) or `AUTH_012` (no active plan). With
  `LICENSING_FILING_REQUIRES_LICENCE=true` (the default), an existing active
  organization needs an active licence to onboard or issue; invoices already
  issued are still filed.
- **A password reset revokes the account's API tokens.**
- **API sign-in needs a verified email address.** `POST /api/auth/login`
  answers 403 `AUTH_013` until it is verified; `POST /api/auth/register`
  sends the link and returns no token; `POST /api/auth/email/resend` sends it
  again. API keys are unaffected.
- **Viewers are read-only.** A member with the `viewer` role gets 403 on any
  write in the portal or the tenant API.
- **Deployments file with ZATCA only unless UAE e-invoicing is switched on**,
  by `PEPPOL_AE_ENABLED=true` or from the console's Countries page. While it
  is off, organizations register in Saudi Arabia only.
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
  admin, or from `platform:enrolment-link` on the server.

### Added

- **UAE e-invoicing API** under `/api/compliance/ae`, where a deployment
  turns on `PEPPOL_AE_ENABLED`: save the seller's UAE details (`PUT
  /profile`), draft an invoice or credit note in PINT AE's shapes (`POST
  /invoices`), issue and send it as a Peppol document (`POST
  /invoices/{invoice}/send`, 422 with every problem when it would be
  refused), and read its status, the documents sent and received, and their
  XML. An organization registered outside the UAE is refused with 403.
- **Signed invoice PDFs**: an invoice PDF is stamped with a PAdES B-B
  signature (ETSI.CAdES.detached) using the issuing device's ZATCA
  certificate, as the Security Features Implementation Standards require for
  a PDF/A-3 invoice. A company not yet connected to ZATCA gets the PDF
  unsigned.
- **ZATCA compliance review** of the documents people see, against the
  Guideline for Tax Invoicing and Records, the XML Implementation Standard
  and the Data Dictionary:
  - Credit and debit notes follow their invoice's type, are refused against
    a rejected invoice or another note, may not credit more than the
    invoice, carry its discount and each line's tax category and
    exemption, and take one of ZATCA's five official reasons, in English
    and Arabic.
  - The printed copy and the PDF title notes as notes, print the issue
    time, the VAT in SAR on a foreign-currency invoice, the buyer's other
    identifier, the seller's CR number, Arabic for every label and each
    exemption reason, and the net unit price on a standard invoice. Only an
    invoice that may be shared prints, and a cleared invoice prints the QR
    code ZATCA returned.
  - The VAT summary puts exports under VATEX-SA-32 and -33 (it had used the
    international transport codes), shows credit notes for earlier periods
    as adjustments, places an invoice in the period of its tax point, and
    lists foreign-currency documents with no exchange rate instead of
    counting them as riyals. The invoices CSV adds SAR columns and signs
    credit notes.
  - An invoice records the moment it was issued (migration 0460): its
    document's issue time, its QR and the 24-hour reporting clock read that,
    not when its draft was started.
  - One note may correct several invoices (their numbers separated by
    commas), each checked and the credit capped by their combined total.
    The invoice form takes a buyer identifier for a buyer with no VAT
    number, a note carries it over, and the form says a note is due within
    15 days of the end of the month the change happened.
  - A rejected invoice is not handed out as a PDF or a print, citations name
    ZATCA's real rules, every amount must be positive including a credit
    note's, a buyer registered outside the Kingdom is identified by its
    registration as another ID, and a payment recorded after the 15 days
    ZATCA allows for invoicing it is flagged.
  - The platform's own invoices are always standard, identify a customer
    without a VAT number by its CR number, check its national address
    first, and carry the subscription's end date (KSA-24, migration 0450).
- **Credit and debit notes from the invoice**: an issued invoice offers
  Credit note and Debit note, which open the new-invoice form filled in
  from it - the buyer, the reference, and for a credit note its lines - so
  only the reason and what differs is left to type.
- **Renewals in the console**: a Renewals page lists every plan ending in
  the next 30 days or lapsed in the last 30, with its contact and a link to
  record the payment.
- **Setup checklist**: a new company's dashboard lists what is left before
  its first invoice - company details, approval, connecting to ZATCA,
  numbering, customers and products, the first invoice - with a link to
  each, and goes away once the required steps are done. The "no active
  certificate" warning now shows only for a company already connected.
- **ZATCA addresses and OpenSSL are settings**: `ZATCA_SANDBOX_URL`,
  `ZATCA_SIMULATION_URL`, `ZATCA_PRODUCTION_URL` and `ZATCA_PORTAL_URL`
  override the published addresses when set; `OPENSSL_BIN` names the
  binary the onboarding commands run, which otherwise comes from the PATH.
- **Email in the reader's language**: verification, password reset, email
  change, invitations, review decisions, renewal and certificate reminders
  and support replies are written in the language the recipient chose for
  the portal, right to left in Arabic.
- **Arabic**: the portal, sign-in, sign-up and My account read in Arabic,
  right to left, with a language switch on every page; the choice is kept
  on the account. Dates, units, validation and messages follow it. The
  admin console stays in English.
- **Public pages**: a landing page with sign-up, features, how it works and
  plan prices (from `BILLING_PRICE_*`), and a privacy notice and terms of
  service in English and Arabic, filled in from the new `COMPANY_*` and
  `CONTACT_*` settings. Sign-up links to `/terms` unless
  `SIGNUP_TERMS_URL` names another page.
- **Reports**: the portal's Reports page shows a period's sales grouped as
  the VAT return's sales boxes - standard rated, citizens' healthcare and
  education, zero rated, exports, exempt - with credit notes taken off and
  foreign currency converted, and downloads it and the invoices behind it as
  CSV. Viewers see it too.
- **Manual billing**: on an organization's Plan and billing page, an Owner or
  Operations admin records a payment (`billing.manage`). It renews the plan
  from its current end - from today once it has lapsed - and can change it.
  The platform invoices each payment through ZATCA from the organization
  `BILLING_ORGANIZATION_ID` names. Customers see their plan, this month's
  usage and their payments' invoices under Plan and billing, and their admins
  are emailed 14 and 3 days before it ends. Plan prices are
  `BILLING_PRICE_*` settings.
- **Invoice PDFs**: an issued invoice downloads as a bilingual PDF/A-3 with
  its XML embedded - from the portal, and from `GET /api/invoices/{id}/pdf`
  (409 for a draft or an uncleared standard invoice). The portal also emails
  it to the buyer, starting from the saved customer's address.
- **Customers and products**: the portal keeps an organization's customers
  (name, VAT number, national address, email) and products (description,
  unit, price, VAT rate). On a new invoice, picking a customer fills the
  buyer and picking a product fills a line; "Save this buyer" keeps a new
  buyer for next time.
- **Support tickets**: customers open tickets from the portal's Support page,
  with attachments, and follow the conversation there. Admins answer from a
  Support queue in the console - reply, add internal notes, assign, resolve -
  with the new `support.manage` permission (Owner, Operations, Support).
  `SUPPORT_NOTIFY_ADDRESS` receives an email for each new ticket.
- **My account**: everyone changes their own name, email address (confirmed
  by a link to the new one), password, and signs out other sessions.
- **Two-factor for everyone**: any user can turn it on, and is then asked for
  a code at sign-in, console and API alike. A company admin can require it
  for the whole company on the Company page.
- **Deleting an account**: a person erases their name, address and sign-in;
  invoices and the audit trail stay with the company, no longer naming them.
- **Admin help with sign-in**: support can email someone a password reset
  link and reset anyone's two-factor.
- **Admin roles**: Owner, Operations, Support and Auditor decide what each
  platform admin may do, on the console and the admin API; owners manage
  admins on an Admins page. Every existing admin is an owner.
- **A viewer role** for a company's accountant or auditor.
- **`zatca_environment` and `zatca_environments` on `/api/health`**: the
  deployment default and every environment some organization files in. And a banner on every page while
  a deployment files with ZATCA's simulation or sandbox.
- **Self sign-up** at `/register`: the person and the company's ZATCA
  details, email verification, forgot and reset password, and a review by a
  platform admin who approves the company and assigns its plan.
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

- **Compliance samples and the CSR name the seller in Arabic**, as its
  invoices do (the Arabic legal name, else the name), so the simulation's
  samples match the documents that follow them.
- **The audit log can be sealed with a key of its own.** With
  `AUDIT_CHAIN_KEY` set (never `APP_KEY`, which is refused), each new entry
  and each chain's head carry an HMAC-SHA256 seal that
  `platform:verify-audit-chain` checks; entries from before the key verify by
  their hash as before, and an unsealed entry after a sealed one is reported.
  On MySQL a chain's head can only move forward and cannot be deleted
  (migration `1851`). Set `AUDIT_CHAIN_KEY` in `.env` (see `.env.example`).
- **A late sale into a filed VAT period reaches the next return.** The open
  period's return now warns of each of the last 12 filed periods whose net
  VAT has moved since filing, with the amount, and says whether to correct
  it in this return (Saudi Arabia under SAR 5,000, IR Art 63; UAE up to AED
  10,000) or to tell the authority (a voluntary disclosure). It used to show
  only on the filed period's own page.
- **A contract's statement nets credit notes on its stage invoices.** A
  stage credited in full no longer counts as billed and retained, so its
  retention cannot be released and charged VAT again; a partial credit takes
  off its share.
- **A UAE summary invoice's 14 days run from the end of its month**
  (Executive Regulation Art 59(13)(b)), not its first day, so one issued by
  the 14th of the next month is no longer marked late.
- **A late summary invoice is flagged.** Drafted with an issue date after the
  law's day (Saudi Arabia: the 15th of the next month, VAT Implementing
  Regulations Art 53(4); UAE: 14 days after the month), it is drafted all the
  same, and `POST /api/v1/invoices/summary` answers `late: true` with the
  reason under `warnings`; the portal shows the warning.
- **A submission handed to the offline queue says so.** Its row stays
  `queued` with no job, as before, but now logs the move as
  `offline_fallback` and records why (`NET_006`, ZATCA did not answer), so
  it no longer reads as a queued row whose job was lost. Three such rows in
  an issue-and-file load run had nothing on them to tell the two apart.
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

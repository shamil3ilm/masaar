# API reference for integrators

Audience: developers of ERPs, POS systems and partner platforms that issue
and file their customers' e-invoices through the API. Start with
[QUICKSTART.md](QUICKSTART.md); this page is the reference behind it. The
invoice fields are in [SAUDI.md](SAUDI.md) and [UAE.md](UAE.md), events in
[WEBHOOKS.md](WEBHOOKS.md), and every path, body and scope is in
[`docs/openapi.yaml`](../openapi.yaml), generated from the routes and held to
them by `OpenapiDriftTest`.

## Two surfaces

| Surface | Who calls it | Credential |
|---|---|---|
| `/api/v1/*` and `/api/license*` | A server acting for one organization: an ERP, a POS back office, a partner | An API key and secret |
| `/api/*` (the rest) | A person signed in, through the portal or an app | A bearer token from `POST /api/auth/login` |

**API key and secret.** Send both on every call, in headers only (never the
query string):

```http
X-API-Key: cp_test_...
X-API-Secret: ...
```

`Authorization: Bearer <base64 of key:secret>` is accepted instead. The key is
either the organization's licence key (issued by a platform administrator) or
one of the organization's own keys (Portal › Integrations › API keys), which
acts under the licence with the scopes, expiry and address list chosen for it.
`cp_test_` is a sandbox key, `cp_live_` a production one. The secret is shown
once and stored only as a hash. The organization id that pipeline bodies name
as `org_id` is shown at the top of Portal › Integrations › API keys.

**Bearer token.** `POST /api/auth/login` with `email` and `password` answers
`data.token` = `{access_token, token_type, expires_in}`. Send
`Authorization: Bearer <access_token>`; `POST /api/auth/refresh` renews it,
`POST /api/auth/logout` ends it. The account's email must be verified
(`403 AUTH_013` otherwise). The token's organization is the one every call
acts for; viewers read, members write, administrators manage webhooks,
settings and onboarding (`403 FORBIDDEN` otherwise).

## Scopes and licence features (`/api/v1`)

| Scope | Routes |
|---|---|
| `invoice.read` | `GET /api/v1/invoices`, `GET /api/v1/invoices/{invoice}`, `GET /api/v1/invoices/splits/{split}` |
| `invoice.submit` | `POST`/`PUT /api/v1/invoices…`, `/api/v1/compliance/generate|validate|submit/{invoiceId}`, `/api/v1/pipeline/submit|batch|split`, `POST /api/v1/invoices/splits…` |
| `invoice.cancel` | `DELETE /api/v1/invoices/{invoice}` (a draft) |
| `compliance.status` | `GET /api/v1/compliance/status/{invoiceId}`, `GET /api/v1/pipeline/status/{invoiceId}` |
| `webhook.manage` | `/api/v1/webhooks…` |
| `schedule.read`, `schedule.manage` | `/api/v1/schedules…` (read; make and change) |
| none | `/api/v1/dashboard`, `/dashboard/health`, `/dashboard/usage`, `/api/license…` |

Implied: `invoice.cancel` gives `invoice.read`, `invoice.batch` gives
`invoice.submit`, `schedule.manage` gives `schedule.read`. A licence issued
without a list gets its tier's: Starter `invoice.submit`, `invoice.read`,
`compliance.status`, `organization.read`, `schedule.read`; Professional adds
`invoice.cancel`, `webhook.manage`, `schedule.manage` and others; Enterprise
and Unlimited add `invoice.batch` and the rest.

Writing a document also needs the licence feature for its kind:
`simplified_invoices`, `standard_invoices`, `credit_notes`, `debit_notes`;
webhooks need `webhook_notifications`. Starter has simplified invoices;
Professional adds the rest. `GET /api/license/features/{feature}` answers
whether the licence has one.

## Responses and errors

```json
{"success": true, "message": "...", "data": {}}
{"success": false, "error": {"code": "...", "message": "...", "category": "...", "retryable": false, "details": {}}}
```

`retryable` is true for 408, 429, 502, 503 and 504; a retryable compliance
error also carries `retry_after` and `max_retries`, and `Retry-After` is set
whenever a retryable error has a wait. Every answer carries `X-Request-Id`
(yours, if you sent a valid one); quote it when asking for help.

**Platform codes** follow the status: `BAD_REQUEST` 400, `UNAUTHORIZED` 401,
`FORBIDDEN` 403, `NOT_FOUND` 404, `METHOD_NOT_ALLOWED` 405, `REQUEST_TIMEOUT`
408, `CONFLICT` 409, `PAYLOAD_TOO_LARGE` 413, `VALIDATION_ERROR` 422 (with
`details` per field), `RATE_LIMITED` 429, `SERVER_ERROR` 500,
`UPSTREAM_ERROR` 502, `SERVICE_UNAVAILABLE` 503, `UPSTREAM_TIMEOUT` 504.

**Named codes:**

| HTTP | `error.code` | Meaning |
|---|---|---|
| 401 | `LICENSE_INVALID_KEY` | No key, an unknown key or a wrong secret, answered alike |
| 403 | `LICENSE_EXPIRED`, `LICENSE_SUSPENDED`, `LICENSE_REVOKED`, `LICENSE_INACTIVE`, `LICENSE_PENDING_ACTIVATION` | The licence cannot be used |
| 403 | `API_KEY_EXPIRED`, `API_KEY_PENDING_APPROVAL`, `API_KEY_ADDRESS_NOT_ALLOWED` | An organization key is past its expiry, awaits approval, or was used from an address not on its list |
| 403 | `AUTH_SCOPE_DENIED` | `details.required_scope` is missing |
| 403 | `LICENSE_FEATURE_UNAVAILABLE` | `details.feature` is missing |
| 403 | `AUTH_ENV_MISMATCH` | A sandbox key on a route that issues or files, for an organization filing live |
| 403 | `LICENSE_INVALID` | A self-hosted deployment's own licence is not valid |
| 403 | `FORBIDDEN` | `org_id` in the body is not the key's organization |
| 409 | `RECORD_IN_USE` | A customer or product an invoice used: archive it (`details.archive`) |
| 422 | `IDEMPOTENCY_KEY_REUSED` | The `Idempotency-Key` was used with another body |
| 422 | `JURISDICTION_UNAVAILABLE` | The organization's country is switched off on this deployment |
| 422 | `JURISDICTION_ENDPOINT_MISMATCH` | A UAE organization on `/api/v1/compliance/*`; `details.use` lists the pipeline routes |
| 422 | `CERTIFICATE_ERROR` | A certificate or credential refused during onboarding |
| 429 | `LICENSE_RATE_LIMITED` | A licence call limit, or too many wrong credentials; `Retry-After` |
| 429 | `LICENSE_QUOTA_EXCEEDED` | The month's invoice allowance is used up; `details` has `limit_type`, `limit`, `current` |
| 500 | `LICENSE_SYSTEM_ERROR`, `SIGNING_ERROR` | Ours; retrying the same request will not help |

**Compliance codes** (category from the prefix):

| Code | HTTP | Retry | Meaning |
|---|---|---|---|
| `AUTH_006` / `AUTH_011` / `AUTH_012` | 403 | no | Organization suspended / not yet approved to file / no active plan |
| `AUTH_010` | 403 | no | Credential environment does not match the target |
| `AUTH_013` | 403 | no | Email address not verified |
| `AUTH_014` | 403 | no | A feature (the pipeline, batching) switched off for the organization by a platform administrator, or the account frozen for an overdue payment (`details.reason` `account_frozen`; notes correcting issued invoices still go through) |
| `VAL_001` / `VAL_002` | 400 | no | A field missing / unreadable (also: a `branch_id` or till not the organization's) |
| `VAL_025` | 422 | no | Already issued; issuing again would fork the hash chain |
| `VAL_099` | 422 | no | The invoice did not pass validation |
| `ZATCA_003` / `ZATCA_005` | 422 | no | The authority rejected the hash / QR |
| `ZATCA_008` / `ZATCA_009` | 409 | no | Already cleared / reported |
| `ZATCA_016`, `ZATCA_019` | 503 | yes | The authority is unavailable / in maintenance |
| `ZATCA_017` | 504 | yes | The authority timed out |
| `ZATCA_018` | 429 | yes | The authority's rate limit |
| `ZATCA_020` | 502 | no | The authority refused the device's certificate and secret: the device is paused (see below) |
| `ZATCA_021` | 502 | no | The authority did not process the document (404, 409, 413, an unreadable 400); nothing was judged |
| `CERT_001` / `CERT_002` / `CERT_004` / `CERT_005` | 404 / 403 / 403 / 422 | no | Certificate not found / expired / revoked / unreadable |
| `CERT_006` | 503 | yes | Revocation status could not be checked |
| `NET_001`, `NET_003`, `NET_006` | 502 | yes | The authority could not be reached |
| `NET_002` | 504 | yes | Connection timed out |
| `RATE_001`, `RATE_002`, `RATE_003`, `RATE_004` | 429 | yes | Platform rate, quota, concurrency or daily limit |
| `SYS_001` / `SYS_007` | 500 / 503 | no / yes | Internal error / maintenance |
| `IDEM_003` / `IDEM_004` | 400 / 409 | no / yes | Key reused with another request / still being processed |

Licence errors from the credential check and the invoice allowance carry
`category` `licensing`; scope and feature refusals carry `license`.

## Limits

| Limit | Default | Past it |
|---|---|---|
| Wrong credentials from one address | 10 per key, 30 across keys, per 5 minutes | `429 LICENSE_RATE_LIMITED`, `Retry-After` |
| Licence calls a minute | Starter 30, Professional 60, Enterprise 120, Unlimited 300 | `429 LICENSE_RATE_LIMITED`, `Retry-After` |
| Licence calls a day | The licence's allowance | `429 LICENSE_RATE_LIMITED` until the day ends |
| Invoices a month | The organization's allowance (unlimited unless set) | `429 LICENSE_QUOTA_EXCEEDED`, before anything is done |
| Organization requests a minute, per kind of endpoint | submissions (`/pipeline/submit`, `/pipeline/batch`) 300, status, health and dashboard reads 600, onboarding 5, everything else 300; unauthenticated 20 | `429 RATE_LIMITED`, wait in `details.retry_after` (no `Retry-After` header) |

Answers carry `X-RateLimit-Limit` and `X-RateLimit-Remaining` (the
organization's budget for that kind of endpoint) and, on `/api/v1`,
`X-RateLimit-Limit-Minute`, `X-RateLimit-Limit-Day`, `X-License-Tier` and
`X-License-Environment`. An invoice counts against the allowance when it is
filed; a filing the authority never answered gives its slot back. Settling a
split sale needs room for two. `GET /api/license/quotas` shows what is left
(`-1` unlimited).

## Idempotency

Send `Idempotency-Key` (at most 255 characters; a blank one counts as none)
on `POST /api/v1/pipeline/submit`, `/pipeline/split`, `POST /api/v1/invoices`
and `POST /api/v1/invoices/splits`. In a batch each invoice carries its own
`idempotency_key` in its body instead, distinct within the batch. A key names
one request in the organization, for good.

- **Same key, same body:** nothing new is made. The answer is the first
  request's invoice as it stands now, and the call carries on where the first
  stopped: a draft is issued, an issued invoice not yet filed is filed (when
  `auto_submit` asks), anything else is reported as it is, a rejection
  included. `POST /api/v1/invoices` and `/invoices/splits` answer `200` with
  `Idempotent-Replayed: true`; the pipeline answers with the status the
  current state earns (201 or 207) and no such header.
- **Same key, different body:** `422 IDEMPOTENCY_KEY_REUSED`, nothing made.
- **What "same body" means:** a SHA-256 of the body exactly as you sent it,
  before any default is filled in, with object keys sorted at every depth
  (field order does not matter; the order of lines does). A retry after
  midnight that leaves out `issue_date` is still the same request.
- A request refused by validation made nothing and may be corrected and sent
  again under the same key.
- With no key, a pipeline request with an `erp_reference_id` and no
  `invoice_number` is matched to the invoice made earlier for that reference.

The step routes replay too: `POST /api/v1/compliance/generate/{id}` on an
invoice already issued answers its stored hash, QR and XML, and
`/compliance/submit/{id}` on an invoice already answered repeats the stored
answer; both with `Idempotent-Replayed: true`, and nothing is signed or sent
again.

## The pipeline

| Route | Does |
|---|---|
| `POST /api/v1/pipeline/submit` | Draft, issue (number, hash, signature, QR) and, unless `auto_submit` is `false`, file one invoice |
| `POST /api/v1/pipeline/batch` | Up to 50 invoices (`invoices`), each taken as `/submit` takes it and answered on its own line with its own `http_status`; one failing stops none |
| `POST /api/v1/pipeline/split` | A sale an insurer pays for (Saudi Arabia): [SAUDI.md](SAUDI.md#split-billing-patient-and-insurer) |
| `GET /api/v1/pipeline/status/{invoiceId}` | The invoice now: `status`, `type`, `hash`, `qr_code`, `signed_xml`, `cleared_xml`, `zatca_response`, `totals`, dates |

The body is the country's invoice ([SAUDI.md](SAUDI.md), [UAE.md](UAE.md))
with `org_id` (required, the key's organization), `auto_submit`, `branch_id`,
`till_id` or `till_number`, `erp_reference_id` and `invoice_type` (taken as
`type`). Without `invoice_number` the invoice is numbered from the
organization's sequence when issued.

The answer's `data`: `invoice_id`, `invoice_number`, `erp_reference_id`,
`status` (`draft`, `issued`, `accepted`, `rejected`), `compliance_status`
(`pending`, `cleared`, `reported`, `rejected`), `hash`, `qr_code`,
`signed_xml` (the document of record: the authority's stamped copy once a
standard invoice is cleared), `cleared_xml`, `zatca_response`
(`submission_id`, `state`, `clearance_status`, `reporting_status`; null when
nothing was filed), `totals`, `errors`, `warnings`, and `rejections` (each
rule: `code`, `category`, `message`) when rejected.

| HTTP | Meaning |
|---|---|
| 201 | Drafted, issued and, if asked, filed or queued |
| 207 | Drafted but not issued, or issued but the filing failed or was rejected: `errors` says which. Send the same request under the same key to carry on |
| 422 | Refused before anything was drafted |

**Step by step (Saudi Arabia).** For a system that drafts first and files
later: `POST /api/v1/invoices` (draft), `PUT`/`DELETE
/api/v1/invoices/{invoice}` (a draft only; an issued invoice is 422),
`POST /api/v1/compliance/generate/{id}` (issue), `/validate/{id}` (check with
the authority without filing), `/submit/{id}` (file), and
`GET /api/v1/compliance/status/{id}`. A UAE organization uses the pipeline;
these routes answer it `422 JURISDICTION_ENDPOINT_MISMATCH`.

## Filing states: what you see

A Saudi standard (B2B) invoice is cleared within the call, because it may not
reach the buyer before; a simplified (B2C) invoice is reported from a queue
within the 24 hours ZATCA allows. `zatca_response.state` is the filing:

| `state` | Invoice `status` / `compliance_status` | What to do |
|---|---|---|
| `queued` | `issued` / `pending` | Nothing: waiting for the queue, or for the authority to come back (offline) |
| `pending_submission` | `issued` / `pending` | Nothing: about to be sent |
| `submitted` | `issued` / `pending` | Nothing. Sent; its answer is awaited, or was lost and is being reconciled. A clearance whose answer was lost is held and never resent automatically |
| `cleared` / `reported` | `accepted` / `cleared` / `reported` | Done |
| `warning` | `accepted` / `cleared` or `reported` | Done; `warnings` lists each as `code: message` |
| `rejected` | `rejected` / `rejected` | Fix the cause and issue a new invoice; `rejections` names each rule. A rejected invoice is not corrected by a note |
| `failed` | `issued` / `pending` | Not judged: send it again (the same pipeline call under the same key, or `/compliance/submit/{id}`; in the portal, **Send again**). `invoice.failed` says `will_retry` or `permanent` |

**A paused device.** When ZATCA answers 401 or 403 to a device's certificate
(`ZATCA_020`), that device stops filing: what it issues meanwhile waits in
the queue, administrators are emailed once, and renewing or onboarding the
device again resumes it and files what waited.

**Notes.** A credit or debit note (`document_type` `credit_note` or
`debit_note`, with `billing_ref` and `adjustment_reason`) is accepted only
once its original is accepted by ZATCA (cleared or reported); before that it
is refused, and against a rejected original it is refused for good. It takes
the type of its original.

**Environment.** Each organization files with one of ZATCA's environments
(sandbox, simulation, production), set by a platform administrator. A sandbox
key is refused (`AUTH_ENV_MISMATCH`) on routes that issue or file once the
organization's environment is production.

**UAE.** Documents travel the Peppol network on two tracks (delivery and the
report to the authority); an invoice issued before the organization's
e-invoicing start date is kept as `not_transmitted` and never sent. See
[UAE.md](UAE.md#4-follow-its-status).

## Documents, lists and versions

- **PDF and receipts** are on the bearer-token API only:
  `GET /api/invoices/{invoice}/pdf?paper=a4|letter|a5|80mm|58mm` (PDF/A-3
  with the XML inside; `409` for a draft or a standard invoice not yet
  cleared), `/receipt-image` and `/escpos`. Known gap: `/api/v1` has no PDF
  or receipt route; use the XML and QR from the pipeline.
- **UAE documents** (`/api/compliance/ae/documents…`, the XML) are on the
  bearer-token API only. Known gap: `/api/v1` has no UAE document routes;
  follow a UAE invoice with `GET /api/v1/pipeline/status/{id}` and webhooks.
- **Pagination:** list routes answer `data` (the items) and `meta`
  (`current_page`, `last_page`, `per_page`, `total`). `?per_page=` (default
  15 for invoices, 20 for webhook logs; at most 100) and `?page=`.
  `GET /api/v1/invoices?status=` filters by status.
- **Versioning:** `/api/v1` is the versioned contract. Its paths and the
  pipeline's answer keys change only with a new version; new optional fields
  and new keys in answers may be added within it, so ignore keys you do not
  know.

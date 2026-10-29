# API reference for integrators

Audience: developers of ERPs, POS systems and partner platforms that issue
and file their customers' e-invoices through the API. Start with
[QUICKSTART.md](QUICKSTART.md); this page is the reference behind it.
Issuing, filing and lists are in [PIPELINE.md](PIPELINE.md); returns,
vouchers, summaries and contracts in [SALES-DOCUMENTS.md](SALES-DOCUMENTS.md). The
invoice fields are in [SAUDI.md](SAUDI.md) and [UAE.md](UAE.md), error codes
in [ERRORS.md](ERRORS.md), events in [WEBHOOKS.md](WEBHOOKS.md), and every
path, body and scope is in [`docs/openapi.yaml`](../openapi.yaml), generated
from the routes and held to them by `OpenapiDriftTest`.

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
| `invoice.read` | `GET /api/v1/invoices`, `GET /api/v1/invoices/{invoice}` and its `/pdf`, `/receipt-image` and `/escpos`, `GET /api/v1/invoices/splits/{split}`, `GET /api/v1/delivery-notes`, `/vouchers…`, `/contracts…` |
| `invoice.submit` | `POST`/`PUT /api/v1/invoices…`, `/api/v1/compliance/generate|validate|submit/{invoiceId}`, `/api/v1/pipeline/submit|batch|split`, `POST /api/v1/invoices/splits…`, `POST /api/v1/invoices/{invoice}/adjustments`, `POST /api/v1/compliance/ae/invoices…`, `POST`/`DELETE /api/v1/delivery-notes…`, `POST /api/v1/invoices/summary`, `POST /api/v1/vouchers…`, `POST /api/v1/contracts…` |
| `invoice.cancel` | `DELETE /api/v1/invoices/{invoice}` (a draft) |
| `compliance.status` | `GET /api/v1/compliance/status/{invoiceId}`, `GET /api/v1/pipeline/status/{invoiceId}`, `GET /api/v1/compliance/ae/…` |
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

Every answer is `{"success": true, "message": ..., "data": ...}` or
`{"success": false, "error": {"code", "message", "category", "retryable", "retry_after", "max_retries", "details"}}`
(the last three only where they apply), and carries `X-Request-Id`. The
`Error` schema in `docs/openapi.yaml` names exactly these keys, and every
error is held to it by `ErrorSchemaTest`. For the main answers the
description also says what `data` holds: the invoice (`Invoice`, with its
`InvoiceLine`s), the pipeline's answer (`PipelineResult`) and status
(`PipelineStatus`), a UAE invoice's status and its documents, and the body
of a webhook delivery (`WebhookDelivery`, under `webhooks`). Real answers
are held to these schemas by `ResponseContractTest`; keys may be added
within the version, so ignore ones you do not know. The envelope and every error code, with what to
do about each, are in [ERRORS.md](ERRORS.md).

## Limits

| Limit | Default | Past it |
|---|---|---|
| Wrong credentials from one address | 10 per key, 30 across keys, per 5 minutes | `429 LICENSE_RATE_LIMITED`, `Retry-After` |
| Licence calls a minute | Starter 30, Professional 60, Enterprise 120, Unlimited 300 | `429 LICENSE_RATE_LIMITED`, `Retry-After` |
| Licence calls a day | The licence's allowance | `429 LICENSE_RATE_LIMITED` until the day ends |
| Invoices a month | The organization's allowance (unlimited unless set) | `429 LICENSE_QUOTA_EXCEEDED`, before anything is done |
| Organization requests a minute, per kind of endpoint | submissions (`/pipeline/submit`, `/pipeline/batch`) 300, status, health and dashboard reads 600, onboarding 5, everything else 300; unauthenticated 20 | `429 RATE_LIMITED`, `Retry-After` (also `retry_after` and `details.retry_after`) |

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

**Other routes that make or act.** `POST /api/v1/schedules`,
`/schedules/{schedule}/runs/{run}/rerun`, `POST /api/v1/webhooks`,
`/webhooks/{webhook}/rotate-secret`, `/invoices/splits/{split}/settle`,
`/invoices/{invoice}/adjustments` and
`POST /api/v1/compliance/ae/invoices` (and the same routes under `/api/…`)
take the key too, with the same rules: the same key and the same request
(method, path and body, hashed as above) answer the first request's answer
as it was, status included, with `Idempotent-Replayed: true`, and nothing is
done again; another request under the key is `422 IDEMPOTENCY_KEY_REUSED`;
while the first is still running, `409 IDEMPOTENCY_KEY_IN_PROGRESS` (send it
again shortly). Only a successful answer is kept, for 30 days; a refused
request may be corrected and sent again under the same key. Pausing,
resuming, cancelling and skipping a schedule need no key: doing one twice
answers `409` with why.

The step routes replay too: `POST /api/v1/compliance/generate/{id}` on an
invoice already issued answers its stored hash, QR and XML, and
`/compliance/submit/{id}` on an invoice already answered repeats the stored
answer; both with `Idempotent-Replayed: true`, and nothing is signed or sent
again.

## Issuing and filing

The pipeline, the step-by-step routes, notes held for approval, filing
states, PDFs and receipts, paging and sync are in [PIPELINE.md](PIPELINE.md).

## Returns, vouchers, summaries and contracts

Returns and exchanges on an issued invoice, disbursement lines, vouchers,
delivery notes with summary invoices, and contracts billed in stages are in
[SALES-DOCUMENTS.md](SALES-DOCUMENTS.md).

## Versioning

`/api/v1` is the versioned contract. Its paths and the pipeline's answer keys
change only with a new version; new optional fields and new keys in answers
may be added within it, so ignore keys you do not know.

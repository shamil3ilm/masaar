# API reference for integrators

Audience: developers of ERPs, POS systems and partner platforms that issue
and file their customers' e-invoices through the API. Start with
[QUICKSTART.md](QUICKSTART.md); this page is the reference behind it. The
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
| `invoice.read` | `GET /api/v1/invoices`, `GET /api/v1/invoices/{invoice}` and its `/pdf`, `/receipt-image` and `/escpos`, `GET /api/v1/invoices/splits/{split}` |
| `invoice.submit` | `POST`/`PUT /api/v1/invoices…`, `/api/v1/compliance/generate|validate|submit/{invoiceId}`, `/api/v1/pipeline/submit|batch|split`, `POST /api/v1/invoices/splits…`, `POST /api/v1/compliance/ae/invoices…` |
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
`/webhooks/{webhook}/rotate-secret`, `/invoices/splits/{split}/settle` and
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

## The pipeline

| Route | Does |
|---|---|
| `POST /api/v1/pipeline/submit` | Draft, issue (number, hash, signature, QR) and, unless `auto_submit` is `false`, file one invoice |
| `POST /api/v1/pipeline/batch` | Up to 50 invoices (`invoices`), each taken as `/submit` takes it and answered on its own line with its own `http_status`; one failing stops none |
| `POST /api/v1/pipeline/split` | A sale an insurer pays for (Saudi Arabia): [SAUDI-BILLING.md](SAUDI-BILLING.md#split-billing-patient-and-insurer) |
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
| 202 | A credit or debit note the organization holds for approval: drafted, not issued. `data.approval` gives the request (`id`, `status` `pending`, `expires_at`). See [Notes held for approval](#notes-held-for-approval) |
| 207 | Drafted but not issued, or issued but the filing failed or was rejected: `errors` says which. Send the same request under the same key to carry on |
| 422 | Refused before anything was drafted |

**Step by step (Saudi Arabia).** For a system that drafts first and files
later: `POST /api/v1/invoices` (draft), `PUT`/`DELETE
/api/v1/invoices/{invoice}` (a draft only; an issued invoice is 422),
`POST /api/v1/compliance/generate/{id}` (issue), `/validate/{id}` (check with
the authority without filing), `/submit/{id}` (file), and
`GET /api/v1/compliance/status/{id}`. A UAE organization uses the pipeline
or its own step routes under `/api/v1/compliance/ae` ([UAE.md](UAE.md#routes));
the Saudi ones answer it `422 JURISDICTION_ENDPOINT_MISMATCH`.

### Notes held for approval

An organization can require a second person for credit and debit notes
(**Settings**, *approvals.notes*, with an optional total from which it
applies). The policy covers notes sent through the API and the pipeline as
well as the portal. While it is on, a note that would be issued -
`/pipeline/submit`, each `/pipeline/batch` line, `/compliance/generate/{id}`,
`/compliance/ae/invoices/{id}/send` - stays a draft and the call answers
**202** with `data.approval`:

```json
{"invoice_id": "…", "status": "draft", "approval": {"id": "…", "status": "pending", "expires_at": "…"}}
```

An owner or administrator approves or rejects it under **Approvals** in the
portal. Approved, the note is issued (and filed, as the organization's
filing mode says) by the person who approved it, every check run again;
`GET /api/v1/pipeline/status/{invoice_id}` then shows it issued. The same
call again, under the same `Idempotency-Key`, answers 202 with the same
request rather than asking twice. A request made with a person's token is
theirs, and they cannot approve it themselves; one made with a licence key
names the key. With nobody in the organization who could approve, the note
is issued at once and the activity record says the approval was bypassed.
Notes the platform issues itself - a billing refund, an insurer's decision
on a split - are not held.

## Filing states: what you see

A Saudi standard (B2B) invoice is cleared within the call, because it may not
reach the buyer before; a simplified (B2C) invoice is reported from a queue
within the 24 hours ZATCA allows. `zatca_response.state` is the filing:

| `state` | Invoice `status` / `compliance_status` | What to do |
|---|---|---|
| `queued` | `issued` / `pending` | Nothing: waiting for the queue, or for the authority to come back (offline) |
| `pending_submission` | `issued` / `pending` | Nothing: about to be sent |
| `submitted` | `issued` / `pending` | Nothing. Sent; its answer is awaited, or was lost and is being reconciled. A clearance whose answer was lost is held, then sent again unchanged (same UUID, hash and counter) after 15 minutes, and ZATCA's answer is recorded; in the portal an administrator can **Send again** once it is held |
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
[UAE.md](UAE.md#following-it-two-tracks).

## Documents, lists and versions

- **PDF and receipts:**
  `GET /api/v1/invoices/{invoice}/pdf?paper=a4|letter|a5|80mm|58mm` (PDF/A-3
  with the XML inside; `409` for a draft or a standard invoice not yet
  cleared), `/receipt-image?paper=80mm|58mm&dots=&dpi=` (a black-and-white
  PNG, one pixel per printer dot) and `/escpos` (the bytes for the printer;
  `cut=0`, `drawer=2|5`). Scope `invoice.read`; the same routes are under
  `/api/invoices/…` for a bearer token.
- **UAE documents:** `/api/v1/compliance/ae/…` drafts, sends and follows a
  UAE invoice and serves its Peppol documents and their XML
  ([UAE.md](UAE.md#routes)); `/api/compliance/ae/…` for a bearer token.
- **Pagination:** list routes answer `data` (the items) and `meta`
  (`current_page`, `last_page`, `per_page`, `total`). `?per_page=` (default
  15 for invoices, 20 for webhook logs; at most 100) and `?page=`.
  `GET /api/v1/invoices?status=` filters by status.
- **Keeping a copy in step (sync):** `GET /api/v1/invoices` and
  `GET /api/v1/compliance/ae/documents` also walk by cursor. Send
  `?cursor=` (empty) for the first page and then each answer's
  `meta.next_cursor`, until it is `null`; the answer's `meta` is then
  `per_page` and `next_cursor`. The order is oldest change first
  (`updated_at`, then `id`), so a walk sees every record once even while
  records are written, and a record changed during the walk comes again
  later as it now is. `?updated_since=` (ISO 8601,
  `YYYY-MM-DDThh:mm:ssZ`) keeps only records changed at or after then, with
  either paging. Start each sync from the `updated_at` of the last record
  the previous one saw: the bound is inclusive, so expect to see a record or
  two again and update them in place. The cursor is opaque; one the API did
  not give is `422 VALIDATION_ERROR` (`details.cursor`). The loop, run by
  the test suite, is in [QUICKSTART.md](QUICKSTART.md#5-keep-your-copy-in-step).
- **Versioning:** `/api/v1` is the versioned contract. Its paths and the
  pipeline's answer keys change only with a new version; new optional fields
  and new keys in answers may be added within it, so ignore keys you do not
  know.

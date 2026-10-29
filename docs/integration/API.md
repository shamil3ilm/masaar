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
  current state earns (201, 202 or 207), also with `Idempotent-Replayed:
  true`.
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
`/invoices/{invoice}/adjustments`, `POST /api/v1/compliance/ae/invoices`,
the money writes `POST /api/v1/vouchers`, `/vouchers/{code}/redeem`,
`POST /api/v1/contracts`, `/contracts/{id}/stages/{stage}/invoice`,
`/contracts/{id}/release`, `POST /api/v1/delivery-notes` and
`/invoices/summary` (and the same routes under `/api/…`) take the key too, with the same rules: the same key and the same request
(method, path and body, hashed as above) answer the first request's answer
as it was, status included, with `Idempotent-Replayed: true`, and nothing is
done again; another request under the key is `422 IDEMPOTENCY_KEY_REUSED`;
while the first is still running, `409 IDEMPOTENCY_KEY_IN_PROGRESS` (send it
again shortly). Only a successful answer is kept, for 30 days; a refused
request may be corrected and sent again under the same key.

Each of these requests runs in one database transaction, so one that fails -
refused, a `503 SYS_008`, an error, a process that died - saved nothing, and
the retry under its key is the first time it really runs. A request whose
process died answers `409 IDEMPOTENCY_KEY_IN_PROGRESS` for five minutes, then
the next retry runs it. Settling a split sale, an adjustment and running a
schedule's period again file with the authority, which cannot be undone, so
they save as they go: when one fails having saved part of its work (a note
drafted or issued), or its process died, its key answers
`409 IDEMPOTENCY_KEY_INCOMPLETE` from then on instead of running it again.
Read what it made (the split, the invoice's notes, the schedule's run) and
send what is left under a new key. Pausing,
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

A Saudi standard invoice may reach the buyer only once ZATCA has cleared it,
so until then `qr_code` and `signed_xml` are `null` - here and in
`/pipeline/status` - and `compliance_status` is `pending`; once cleared they
are the QR and the document ZATCA stamped. A simplified invoice returns both
as soon as it is issued.

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

## Returns, exchanges and corrections

`POST /api/v1/invoices/{invoice}/adjustments` (and `/api/invoices/{invoice}/adjustments`
with a person's token) records what happened to an issued invoice and makes
the documents it calls for, in one call. Send an `Idempotency-Key`: a retry
answers the first call's answer. Needs `invoice.submit` and the
`credit_notes` licence feature; it counts up to two documents.

| `kind` | Also send | Makes |
|---|---|---|
| `return` | - | A credit note for the whole invoice |
| `partial_return` | `lines: [{line, quantity}]` (`line` from 1) | A credit note for what came back |
| `discount` | `discount_amount` (before VAT) or `discount_percent`; optional `lines: [{line}]` | A credit note for the discount |
| `exchange` | `lines`, `items: [{description, description_ar, quantity, unit_price}]` | A credit note, and a draft invoice for the items |
| `price_difference` | `lines`, `items` | A debit note (UAE: a tax invoice) or a credit note for the difference |
| `replacement` | `lines`, `note` | Nothing: the record only |
| `buyer_correction` | `buyer: {buyer_name, buyer_vat_number, buyer_address, …}` | A credit note for the whole invoice, and a draft invoice to the corrected buyer |

`note` (up to 500 characters) is kept on the record. Items are at net
prices and taxed as the first line returned unless they give `tax_category`
and `tax_rate`. `preview: true` answers `200` with `data.preview` - each
document's role, type, lines and totals - and makes nothing.

Made, it answers `201`:

<!-- example: response -->
```json
{"adjustment": {"id": "…", "kind": "partial_return", "reason": "In case of goods or services refund. / عند ترجيع السلع أو الخدمات", "details": {"lines": [{"line": 1, "description": "Office chair", "quantity": "2"}]}},
 "documents": [{"role": "credit", "invoice_id": "…", "document_type": "credit_note", "invoice_number": "…", "status": "issued", "total": "575.00", "approval": null, "message": null}]}
```

`status` is `issued`, `pending_approval` (the organization holds notes;
`approval` is the request, as under [Notes held for approval](#notes-held-for-approval))
or `draft` (a new invoice, for you to issue). The rules of a note apply: in
Saudi Arabia the invoice must be accepted by ZATCA, and credit notes never
come to more than it and its debit notes; a quantity already returned cannot be returned
again. A refusal answers `422` and makes nothing.

## Disbursements

On `POST /api/v1/invoices` (and the pipeline), a line with
`"kind": "disbursement"` is a cost paid in the customer's name and for their
account (Saudi Arabia, GCC VAT Agreement Art 26(6)(c)). Send
`"disbursements_confirmed": true` with it - the seller confirms the
conditions - or the request answers `422` on `disbursements_confirmed`. The
line is drafted out of scope (`tax_category` `O`, `exempt_code`
`VATEX-SA-OOS`, a bilingual `exempt_reason` unless you give one) whatever
VAT it was sent with, and keeps `kind` on the invoice. A UAE invoice refuses
such a line (`422` on `lines.N.kind`).

## Vouchers

| Call | Does |
|---|---|
| `POST /api/v1/vouchers` | Records a voucher sold: `face_value`, `sold_on`, `payment_method` (`cash`, `card`, `bank_transfer`, `cheque`, `other`), optional `code` (made if left out), `expires_on`, `customer_id`, `holder_name`, `reference`. No invoice is made: selling a voucher is not a supply. `sold_for` other than the face value is refused (`422`) |
| `GET /api/v1/vouchers`, `GET /api/v1/vouchers/{code}` | Each with `balance`, `status` (`active`, `used`, `expired`) and its `redemptions` |
| `POST /api/v1/vouchers/{code}/redeem` | Pays an issued invoice with it: `invoice_id` or `invoice_number`, optional `amount` (default as much as both allow) and `redeemed_on`. Recorded as the invoice's payment, method `voucher` |

Reading needs `invoice.read`, writing `invoice.submit`.

## Delivery notes and summary invoices

A month's deliveries to one customer on one summary tax invoice.

| Call | Does |
|---|---|
| `POST /api/v1/delivery-notes` | Records a delivery: `customer_id`, `delivered_on`, optional `number` and `reference`, `lines: [{description, description_ar, quantity, unit_code, unit_price, tax_category, tax_rate, exempt_code, exempt_reason}]` at net prices. Not a tax document |
| `GET /api/v1/delivery-notes` | Lists them under `delivery_notes`, with `meta` (`total`, `page`, `per_page`); `?customer_id=`, `?invoiced=0` or `1` |
| `DELETE /api/v1/delivery-notes/{id}` | Removes one no invoice has taken (`422` otherwise) |
| `POST /api/v1/invoices/summary` | Drafts one invoice for a customer's delivery notes not yet invoiced: `customer_id`, `from`, `to` (within one calendar month), optional `issue_date` (not before `to`) and `due_date` |

The summary answers `201` with `invoice` (a draft; issue it as any draft),
the `delivery_notes` it took and `issue_by`, the last day the law allows
(Saudi Arabia: the 15th of the next month; UAE: 14 days after the month).
Each line names its delivery and date. In Saudi Arabia it is a standard
invoice with KSA-2 position 6 set; in the UAE a tax invoice with
`transaction_flags` `00010000`, the period as its invoicing period. Writing
needs `invoice.submit` (the summary also `standard_invoices`); reading
`invoice.read`. `/api/...` with a person's token is the same.

## Contracts billed in stages

| Call | Does |
|---|---|
| `POST /api/v1/contracts` | Records a contract: `customer_id`, `reference` (unique; BT-12 on its invoices), `title`, `title_ar`, `value` before VAT, optional `retention_percent` (at most 50), `tax_category`, `tax_rate`, `exempt_code`, `exempt_reason`, and `stages: [{name, name_ar, percent or amount}]` (together at most the value) |
| `GET /api/v1/contracts`, `GET /api/v1/contracts/{id}` | Lists them under `contracts`; one with its `statement` |
| `POST /api/v1/contracts/{id}/stages/{stage}/invoice` | Drafts the stage's invoice, its value less the retention; optional `issue_date`, `due_date` |
| `POST /api/v1/contracts/{id}/release` | Drafts the invoice releasing retention: `amount` (default all that is held), optional `issue_date`, `due_date` |

Each write answers with `contract` and `statement` (a stage or a release
also with the drafted `invoice`); the statement holds `value`, `billed`,
`retained`, `released`, `retention_held`, `remaining`, `invoiced` (all before
VAT), `retention_due_by` (when the tax on retention still held falls due:
12 months in Saudi Arabia, one year in the UAE), `stages` and `releases`
with their invoices. The invoices are drafts; issue them as any draft. In
the UAE they carry `transaction_flags` `00001000`. Writing needs
`invoice.submit` and `standard_invoices`; reading `invoice.read`.

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

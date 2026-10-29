# Issuing, filing and reading invoices

Audience: developers of ERPs, POS systems and partner platforms that issue
and file invoices through the API. Keys, scopes, limits, idempotency and
errors are in [API.md](API.md); the invoice fields are in [SAUDI.md](SAUDI.md)
and [UAE.md](UAE.md).

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
so until then `qr_code` and `signed_xml` are `null` - here, in
`/pipeline/status`, in `GET /api/v1/invoices` (one invoice, the list and the
sync walk, and on `/api` too) and in `/compliance/sa/status` - and
`compliance_status` is `pending`; once cleared they
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

## Documents and lists

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

# Filing states

Audience: engineers changing issuing or filing, and reviewers checking that
a document's status cannot be forged.

Three records move, each on its own track, and each has one writer that
checks every move against an allowed-transitions table. A move the table does
not allow is refused, and the model checks again for any write that bypasses
the writer.

| Record | Column | Table of moves | Writer |
|---|---|---|---|
| Invoice | `invoices.status` | `InvoiceStatus::next()` | `IssuingLifecycle` (issue), `InvoiceVerdict` (verdict) |
| Saudi submission | `invoice_submissions.state` | `SubmissionState::next()` | `SubmissionLedger` only |
| Network document (UAE) | `peppol_documents.status`, `reporting_status` | `DocumentStatus` (delivery and reporting tracks) | `DocumentStatus::transition()` / `transitionReporting()` only |

<!-- app/Domains/Invoice/Enums/InvoiceStatus.php; app/Domains/Compliance/Countries/SaudiArabia/Filing/SubmissionState.php;
     app/Domains/Compliance/Networks/Peppol/Sending/DocumentStatus.php; the model guards: Invoice::booted (updating),
     InvoiceSubmission::booted (IllegalSubmissionMoveException) -->

## 1. The invoice

| From | May move to | Set by |
|---|---|---|
| (new) | `draft` | `InvoiceDrafter` |
| `draft` | `issued` | `IssuingLifecycle` — hash, chain place and signed XML are fixed here |
| `issued` | `accepted`, `rejected` | `InvoiceVerdict`, on an authority's verdict only |
| `rejected` | `accepted`, `rejected` | `InvoiceVerdict`, a retried or re-answered filing |
| `accepted` | — (final) | a late answer leaves it as it is |

- There is no `cancelled` or `void`. A draft that is abandoned is deleted; an
  issued invoice is never withdrawn, only corrected by a credit or debit note
  (ZATCA E-Invoicing Detailed Guidelines: no cancellation).
- "Cleared" and "reported" are not invoice statuses. They are the Saudi
  submission's state and the authority's words in `zatca_response`.
- **Verdict only.** An answer that judged nothing — 401, 404, 409, 413, 5xx, a
  timeout — leaves the invoice `issued`. Only a real accept or reject moves it.
- A UAE invoice stays `issued`: its progress is the network document's, below.

## 2. The Saudi submission

`SubmissionLedger` (`open`, `claim`, `transition`, `recordResponse`) is the
only writer, and logs every move to `submission_state_logs` with the request
id. A submission is created `queued` or `pending_submission`
(`SubmissionState::initial()`).

| From | May move to |
|---|---|
| `draft` | `queued`, `pending_submission`, `failed`, `cancelled` |
| `queued` | `queued`, `pending_submission`, `cancelled`, or any answer |
| `pending_submission` | any answer |
| `submitted` | any answer |
| `failed` | `failed`, `pending_submission`, `queued` |
| `rejected` | `pending_submission`, `queued` |
| `cleared`, `reported`, `warning`, `cancelled` | — (final; the model refuses any change to the row) |

"Any answer" is `submitted`, `cleared`, `reported`, `warning`, `rejected`,
`failed`. `submitted -> submitted` is a clearance ZATCA has not decided yet.

Who causes each move:

| Move | Cause |
|---|---|
| created | `SubmissionTracker`, through `SubmissionLedger::open` |
| `-> pending_submission` | `SubmissionLedger::claim` — the job or a retry, under a row lock |
| `-> submitted` | just before the request |
| `-> cleared / reported / warning / rejected / failed` | `SubmissionLedger::recordResponse` |
| `-> failed` | an exception; the queue giving up; `fatoora:recover-submissions` on a stale row |
| `-> queued` | the offline fallback taking it over; a lost job requeued |
| `-> cancelled` | `SubmissionTracker::cancel`, before sending |

### Held, paused and the reconciliation hold

None of these is a state of its own:

- **Held** — the submission is `failed` with `ZATCA_NOT_PROCESSED`
  (`ZATCA_021`: 404, 409, 413 or an unreadable 400). Nothing was judged; it is
  resent once fixed.
- **Paused** — a 401 or 403 (`ZATCA_UNAUTHORIZED`, `ZATCA_020`) pauses the device
  (`egs_units.filing_paused_at`, `FilingPause`). Nothing more is sent from it;
  what it issues meanwhile waits in the offline queue; its administrators are
  told once. Renewing or re-onboarding resumes it and files what waited
  (`FilingResume`). The Detailed Guidelines' response table: "401
  Unauthorized — check authentication certificate and secret and resubmit".
- **Not received** — ZATCA's table says 500, 503 and 504 mean "invoice not
  received — please resend", as it says of 429. A clearance or a report with
  one of these is `failed` and retried with the queue's backoff; Send again
  works on it as on any failure.
- **Reconciliation hold** — a clearance whose outcome is ambiguous (a read
  timeout, a connection dropped mid-response, a 5xx the table does not list,
  or an answer that could not be recorded) stays `submitted`, and its job
  stops without a retry. Nothing sends from it while its answer may still
  come. Once it has not moved for the stale window
  (`fatoora.recovery.stale_minutes`, 15), `fatoora:recover-submissions`
  (every ten minutes) or an administrator's **Send again**
  (`InvoiceSubmission::isHeld`) moves it `submitted -> failed` and sends the
  *same signed document* again — same UUID, hash, ICV and PIH, never re-signed
  or re-numbered. ZATCA's answer settles it: cleared (now, or already) is
  recorded `cleared`; a refusal naming rules is `rejected`; anything that
  judged nothing is `failed` again. Sending the same document twice is safe:
  "ZATCA does not allow the same document to be submitted twice. However, this
  will not be rejected at the time of submission" (Detailed Guidelines, FAQ
  "Can I submit the same invoice twice?"), and the B2B outage scenario has the
  taxpayer keep retrying the clearance at regular intervals until it succeeds
  (Detailed Guidelines, "Failure to receive response from ZATCA — B2B
  scenario"; "Failure scenarios": "continue re-submitting same invoice until
  ZATCA servers are responding"). A report is resent from `submitted` the same
  way.

### Before anything is sent

`SubmissionGuard::check()` refuses unless every check passes, in this order:
the kill switch (submission, then clearance or reporting, globally or per
organization), the organization's status (`FilingClearance` — not its plan:
an issued invoice is filed regardless), certificate health (an expired or
invalid certificate files nothing; issuing already refuses one with
`CERT_EXPIRED`), the device not paused, the device and
organization rate limits, concurrency, duplicates, and a submittable invoice.

## 3. The offline queue

A work record, not a filing state (`offline_queue`, `OfflineQueue`). Each move
is a conditional update, so two drains cannot take one item.

| From | May move to |
|---|---|
| `pending` | `processing`, `cancelled` |
| `processing` | `completed`, `pending` (retry), `failed` |
| `failed` | `pending` — an operator, from the admin console (`Requeuer`) |

`fatoora:process-offline` drains it on the schedule;
`compliance:cleanup-offline-queue` removes finished items. The 24-hour
reporting deadline for simplified invoices keeps running while an item waits
(`LateReporting`, `fatoora:overdue-submissions`).

## 4. The UAE network document

A UAE invoice is sent as a Peppol document through the organization's
accredited service provider. The provider delivers it to the buyer's provider
and reports its tax data to the authority in parallel, so a document has two
statuses (Electronic Invoicing Guidelines v1.1, the five-corner flow), and
neither waits for or overwrites the other.

**Delivery** (`status`):

| From | May move to |
|---|---|
| `ready` | `sending`, `failed` |
| `sending` | `sent`, `not_exchanged`, `ready` (no verdict, try again), `rejected`, `failed` |
| `sent` | `delivered`, `rejected` (the buyer's provider's answer) |
| `failed` | `ready` — only an operator, `peppol:resend` |
| `delivered`, `rejected`, `not_exchanged` | — (final) |
| `not_transmitted` | — (final, no move leaves it) |
| `received` | inbound documents; delivery never moves |

- `not_exchanged` — a reserved, report-only endpoint: there is no buyer's
  provider, so the document is done once reported.
- `not_transmitted` — issued before the organization's e-invoicing start date
  (`EinvoicingStart`). Built, checked and kept as the record, and never sent:
  not by a later start date, a resend or a recovery sweep, because the mandate
  does not reach back to invoices issued before it.

**Reporting** (`reporting_status`), from the moment the provider takes it:

| From | May move to |
|---|---|
| (none) | `pending`, `reported`, `report_failed` |
| `pending` | `reported`, `report_failed` |
| `report_failed` | `pending` (`peppol:retry-reports`), `reported` |

Callbacks arrive late, twice and out of order, so a status never moves
backwards on its track. Every move re-reads the row under a lock, and every
move — and every provider message that moves nothing — is appended to the
document's history (`DocumentHistory`) in the same transaction.
`peppol:poll-status` asks about sent documents whose answer has not come, for
up to fourteen days; `peppol:recover-sending` recovers stuck sends.

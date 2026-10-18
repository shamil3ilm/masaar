# Reading the audit records

**Written for a tax auditor or compliance reviewer, and their technical
advisor.** It says what is recorded when someone acts on an invoice, where it
is, and what each field means — enough to answer "who did this, and when"
without reading the source.

---

## 1. Two kinds of record

**`audit_logs`** — a table. Who acted, on what, and what changed. Queryable,
retained with the rest of the compliance data, and the place to start.

**`submission_state_logs`** — also a table. Every state an invoice's
submission passed through on its way to the authority, and what moved it.
Use this when `audit_logs` says a submission happened and you need the
sequence of it: the attempts, the retries, and what the authority answered
each time.

| Column | Meaning |
|---|---|
| `submission_id` | Which submission, joining to `invoice_submissions` |
| `from_state`, `to_state` | The transition. Null `from_state` is the first entry |
| `trigger` | What caused it — an API call, a queue worker, a scheduled retry |
| `context` | The authority's reply, or the error, as JSON |
| `actor_type`, `actor_id` | A user, or the system |
| `ip_address` | Where the request came from, when there was one |
| `request_id` | The request it happened in, or the request that queued the job that did it (section 4) |
| `created_at` | When, in UTC |

There are no separate per-submission log files. The table above is the record
of each submission's progress.

---

## 2. The `audit_logs` table, field by field

| Column | Meaning |
|---|---|
| `id` | The record's own identifier |
| `org_id` | Which taxpayer. Null only for events before a taxpayer is established, such as a failed sign-in |
| `user_id` | Which user account. Null when the actor was the system itself — a scheduled task or a queue worker |
| `action` | What happened. See below |
| `entity_type` | What it happened to, as a class name: an invoice, an organization, a certificate |
| `entity_id` | Which one |
| `old_values` | The fields as they were, for a change |
| `new_values` | The fields as they became |
| `ip_address` | Where the request came from |
| `user_agent` | What client made it |
| `metadata` | Context that is not a field change — for a submission, the outcome and the authority's reference |
| `request_id` | The request it was written in (section 4) |
| `created_at` | When, in UTC |

An entry is written once. The application refuses to change or delete one,
as ZATCA's prohibited functions require of system logs (E-Invoicing Detailed
Guidelines, 5.6). `updated_at` is a column from before that rule and is no
longer written.

Indexed by action, by entity, and by taxpayer with date, so the three questions
an auditor asks — what happened to this invoice, what did this user do, what
happened in this period — are all direct lookups.

### What `action` can say

Recorded through one service, so the vocabulary is closed rather than whatever
each caller chose:

- **`created`, `updated`, `deleted`** — a record changed. `old_values` and
  `new_values` carry the before and after.
- **a ZATCA submission** — a document was sent to the authority, with success
  or failure and the authority's response in `metadata`.
- **an authentication event** — a sign-in, a sign-out, a failure.
- **a security event** — a refusal, a privilege change, a credential operation.

---

## 3. What is deliberately *not* recorded

The logs are a record of activity, not a second copy of the data. A VAT number,
a buyer's name or an invoice's contents are not written into log lines; a
sanitiser strips values of that kind before anything is written, and where a
value must be correlatable it is hashed rather than reproduced.

This is intentional and it has a consequence worth stating plainly to an
auditor: **the logs tell you that an invoice was created, changed or submitted,
and by whom. They are not where you read the invoice.** The invoice is in
`invoices` and in the XML stored with it — see `docs/DATA-FLOW.md`.

Credentials, private keys and certificates are never logged in any form.

---

## 4. Tying a log line to a request

Every log line written while serving a request carries:

| Field | Meaning |
|---|---|
| `request_id` | A correlation identifier, unique to that request |
| `org_id` | Which taxpayer |
| `user_id` | Which credential, where there was one |
| `route` | Which endpoint, as a pattern rather than a path |

The same identifier is returned to the caller in the `X-Request-Id` response
header. So when a taxpayer reports a problem and quotes that value, every line
written while their request was being served can be gathered in one query —
including the refusal itself, since the identifier is assigned before any check
that might reject the request.

The identifier is not only in the log. It is stored on what the request
wrote - `audit_logs`, `invoice_submissions`, `submission_state_logs` and
`reporting_attempts` (and `usage_events` for a partner call) - and carried
into every job the request queues, so a filing that happens minutes later in
a worker still names the request that asked for it:

```sql
SELECT 'audit' AS kind, created_at, action AS what FROM audit_logs WHERE request_id = :id
UNION ALL SELECT 'submission', created_at, state FROM invoice_submissions WHERE request_id = :id
UNION ALL SELECT 'state', created_at, to_state FROM submission_state_logs WHERE request_id = :id
UNION ALL SELECT 'attempt', attempted_at, outcome FROM reporting_attempts WHERE request_id = :id
ORDER BY created_at;
```

A caller may supply its own identifier, which is kept so a trace survives a
gateway or a retry. It is accepted only if it is identifier-shaped: short,
and made of letters, digits, dots, dashes and underscores. Anything else is
replaced. The reason matters for an auditor's confidence in the logs — a value
that goes into every line of a request is a way to forge log entries if it is
taken on trust.

---

## 5. Who made each record, and how

Besides the audit log, each record says who acted on it, as a user id - never
a copy of a name or an address, so an account that is deleted (anonymised)
changes no record:

| Column | On | Meaning |
|---|---|---|
| `created_by` | entities, invoices, submissions | Who made it. Under its own name where a table already had one: `invited_by`, `issued_by` (licences), `opened_by`, `linked_by`, `recorded_by` (payments) |
| `updated_by` | entities | Who last changed it (`set_by` on a feature switch) |
| `deleted_by` | what people may remove | Who removed it; cleared when it is brought back |
| `issued_by` | invoices | Who issued it: the person signed in when it left draft |
| `created_via` | invoices, customers, products, payments | How it arrived: `portal`, `admin`, `api`, `pipeline`, `batch`, `import`, `system` (a job, the scheduler, a command) or `edge` |
| `license_id` | invoices, customers, products | The partner licence (API key) it came through: which integration acted for the taxpayer |
| `customer_id` | invoices | The saved customer it was for. The buyer fields remain the document and do not change with the customer |

A job keeps the person whose request queued it, and records `system` as how
it arrived. Null means unknown: a record made before this was kept, or by an
integration or the scheduler with no person behind it.

Removing a customer, product, QR code, webhook, invitation, compliance
profile, group, branch, licence or account keeps the row with `deleted_at`
and `deleted_by`. Invoices and their lines, submissions and their states,
the chain, Peppol documents, payments, late reports, reporting attempts and
the audit log are never removed (ZATCA E-Invoicing Detailed Guidelines 5.4
and 5.6). A draft that was never issued may be discarded; its full contents
are kept in its `Invoice.deleted` audit entry.

`tests/Feature/Architecture/TableTimestamps.php` classifies every table and
says which of these it keeps; `docs/schema.sql` is the schema itself.

---

## 6. Worked examples

**"Who changed this invoice, and what did they change?"**

```sql
SELECT created_at, user_id, action, old_values, new_values, ip_address
FROM audit_logs
WHERE entity_type LIKE '%Invoice' AND entity_id = :invoice_id
ORDER BY created_at;
```

**"Everything that happened for this taxpayer in a period."**

```sql
SELECT created_at, user_id, action, entity_type, entity_id
FROM audit_logs
WHERE org_id = :org_id AND created_at BETWEEN :from AND :to
ORDER BY created_at;
```

**"Every submission of this invoice and its outcome."**

```sql
SELECT state, previous_state, state_changed_at, clearance_status, zatca_uuid
FROM invoice_submissions
WHERE invoice_id = :invoice_id
ORDER BY state_changed_at;
```

**"Was anything rejected, and why?"**
State `rejected` above; the authority's own words are in
`invoices.zatca_response`.

---

## 7. Retention and integrity

Audit records are retained with the compliance data. A monthly task creates
partitions ahead of need and nothing is detached - archiving past seven years
is available but is not scheduled - so no audit record is removed by the
platform at all.

**What the platform does and does not claim.** `audit_logs` is an
application-level record: it is written by the application, and an actor with
direct database access could alter it. It is not presented as cryptographic
proof. The tamper-evidence in this platform is the hash chain over the
documents themselves — each document's hash and the previous document's hash,
with the sequence enforced by database constraints — and that is what
`docs/DATA-FLOW.md` section 3 shows how to verify. The two serve different
purposes and an audit should use both: the chain to establish that the
documents are intact and complete, the audit log to establish who acted.

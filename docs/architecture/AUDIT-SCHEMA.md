# Reading the audit records

Audience: tax auditors, compliance reviewers and their technical advisors.
What is recorded when someone acts, where, and what each field means.

## 1. The records

| Record | Answers |
|---|---|
| `audit_logs` | Who acted, on what, what changed — the place to start |
| `submission_state_logs` | Every state a Saudi submission passed through, and what moved it ([FILING-STATES.md](FILING-STATES.md)) |
| `reporting_attempts` | Each attempt to report a simplified invoice, with its outcome |
| `peppol_document_events` | Every move of a UAE network document, and every provider message |
| Actor columns on each record | Who made, changed, issued or removed it (section 4) |

## 2. `audit_logs`

| Column | Meaning |
|---|---|
| `id` | The entry |
| `org_id` | Which organization. Null only before one is established (a failed sign-in) |
| `user_id` | Which account. Null when the system acted (a job, the scheduler) |
| `action` | What happened, as a dotted name (below) |
| `entity_type`, `entity_id` | What it happened to |
| `old_values`, `new_values` | The fields before and after, for a change |
| `metadata` | Context that is not a field change (a submission's outcome, the authority's reference) |
| `ip_address`, `user_agent` | Where the request came from, and which client |
| `request_id` | The request it was written in (section 3) |
| `created_at` | When, in UTC |

An entry is written once: the model refuses to update or delete one
(`AuditLog`), as the Detailed Guidelines' prohibited functions require of
system logs (E-Invoicing Detailed Guidelines 5.6). There is no `updated_at`.

Every entry goes through `AuditService`. Actions are dotted names:

- `<Model>.created`, `<Model>.updated`, `<Model>.deleted` — a record changed,
  with before and after. A discarded draft's full contents are kept in its
  `Invoice.deleted` entry.
- `invoice.issued` — a document left draft.
- `zatca.submission.success`, `zatca.submission.failed` — a filing and the
  authority's response.
- `auth.*` — sign-in, sign-out, failure; `security.*` — refusals, privilege
  and credential changes.
- Administrative and settings changes under their own names, for example
  `zatca.environment.changed` and `uae.einvoicing_start.changed`.

Indexed by action, by entity, and by organization with date.

**Not recorded.** Logs are a record of activity, not a copy of the data. Log
lines pass through `LogSanitizer` and the `RedactSecrets` processor; VAT
numbers, buyer names and invoice contents are not written to log lines, and
credentials, private keys and certificates are never logged. The invoice is
read in `invoices` ([DATA-FLOW.md](DATA-FLOW.md)).

An admin change records its input and its before and after values through
`AuditAdminAction`, which masks them before the entry is written and so
before it is chained: by field name (passwords and passphrases, secrets,
tokens, keys, one-time codes, PINs, card and bank account details,
authorization headers, cookies, signatures) and by shape wherever they
appear (card numbers, IBANs, bearer tokens and JWTs, private keys, through
`LogSanitizer`). Entries written before this are unchanged, so the chain
still verifies.

## 3. Tying a record to a request

`LogContext` gives every request an id and returns it in the `X-Request-Id`
response header. A caller's own id is kept only if it matches
`[A-Za-z0-9._-]{8,64}`, so the value cannot forge log lines. The id is on
every log line of the request, is stored on `audit_logs`,
`invoice_submissions`, `submission_state_logs`, `reporting_attempts` and
`usage_events`, and is carried into every job the request queues:

```sql
SELECT 'audit' AS kind, created_at AS at, action AS what FROM audit_logs WHERE request_id = :id
UNION ALL SELECT 'submission', created_at, state FROM invoice_submissions WHERE request_id = :id
UNION ALL SELECT 'state', created_at, to_state FROM submission_state_logs WHERE request_id = :id
UNION ALL SELECT 'attempt', attempted_at, outcome FROM reporting_attempts WHERE request_id = :id
ORDER BY at;
```

## 4. Who made each record, and how

Records store user ids, never copies of names, so anonymising a deleted
account changes no record. The columns are written by the `RecordsActors`
concern and `Origin`, never by a controller.

| Column | On | Meaning |
|---|---|---|
| `created_by`, `updated_by` | entities | Who made it, who last changed it |
| `deleted_by` | records people may remove | Who removed it |
| `issued_by` | invoices | Who issued it |
| `created_via` | invoices and notes, customers, products, payments | `portal`, `admin`, `api`, `pipeline`, `batch`, `import`, `system` or `edge` |
| `license_id` | the same | The partner licence (API key) it came through |

A job keeps the person whose request queued it and records `system` as the
channel. Removable records (customers, products, branches, users ...) keep
`deleted_at` and `deleted_by`. Compliance and financial records — invoices
and lines, submissions and their states, the chain, network documents,
payments, reporting attempts, the audit log — have no `deleted_at` and are
never removed (Detailed Guidelines 5.4, 5.6). Every table's class is declared
in `tests/Feature/Architecture/TableTimestamps.php`.

## 5. Worked examples

```sql
-- Who changed this invoice, and what did they change?
SELECT created_at, user_id, action, old_values, new_values, ip_address
FROM audit_logs WHERE entity_type LIKE '%Invoice' AND entity_id = :invoice_id
ORDER BY created_at;

-- Every filing of this invoice and its outcome
SELECT state, previous_state, state_changed_at, clearance_status, zatca_uuid
FROM invoice_submissions WHERE invoice_id = :invoice_id ORDER BY state_changed_at;
```

## 6. What the audit log is, and is not

`audit_logs` is an application record: an actor with direct database access
could alter it, and it is not presented as cryptographic proof. The
tamper-evidence is the hash chain over the documents
([DATA-FLOW.md](DATA-FLOW.md) section 3). Use both: the chain to show the
documents are intact and complete, the log to show who acted. Retention is in
[../compliance/POLICIES.md](../compliance/POLICIES.md).

# Reading the audit records

**Written for a tax auditor or compliance reviewer, and their technical
advisor.** It says what is recorded when someone acts on an invoice, where it
is, and what each field means — enough to answer "who did this, and when"
without reading the source.

One word of warning about naming: `docs/audit/` in this repository is an
*engineering* audit of the source code. It is not a record of invoicing
activity and is not what this document describes.

---

## 1. Two kinds of record

**`audit_logs`** — a table. Who acted, on what, and what changed. Queryable,
retained with the rest of the compliance data, and the place to start.

**Log channels** — files. What the platform did, in sequence, including the
exchanges with the authority. Use these when the table says something happened
and you need the detail of it.

| Channel | What it carries |
|---|---|
| `zatca-submissions` | Each submission attempt and the authority's reply |
| `zatca-compliance` | Onboarding and the compliance checks |
| `zatca-webhooks` | Notifications sent onward to the taxpayer's systems |
| `zatca-audit` | Security-relevant events |
| `zatca-errors` | Failures |

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
| `created_at` | When, in UTC |

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

A caller may supply its own identifier, which is kept so a trace survives a
gateway or a retry. It is accepted only if it is identifier-shaped: short,
and made of letters, digits, dots, dashes and underscores. Anything else is
replaced. The reason matters for an auditor's confidence in the logs — a value
that goes into every line of a request is a way to forge log entries if it is
taken on trust.

---

## 5. Worked examples

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

## 6. Retention and integrity

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

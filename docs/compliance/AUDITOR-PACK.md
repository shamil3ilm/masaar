# Auditor pack

Audience: an external auditor and their technical advisor. What the system
guarantees, the evidence for each guarantee, and how to check it yourself.
Detail is linked, not repeated.

## 1. Guarantees and where the evidence is

| Guarantee | How it is enforced | Evidence / how to check |
|---|---|---|
| An issued invoice's content does not change | Once a document leaves draft the model refuses a change to any column except `Invoice::MUTABLE_AFTER_FINALIZED`: `status` (only along `InvoiceStatus::next()`), `hash`, `qr_code` and `signed_xml` (written at issuance, and again only by an issuance that did not finish), `cleared_xml` and `zatca_response` (the authority's answer), `notes` and `erp_reference_id`. Buyer, lines, amounts, dates, number and counter are frozen. Corrections are credit or debit notes, which in Saudi Arabia need an original the authority has accepted (IR Art 54(4), BR-KSA-56). The guard is in the application (Eloquent), not the database, and the platform does not recompute a stored hash: the chain check compares stored values | Recompute the hash from `signed_xml` yourself and compare it with `invoices.hash` and the chain entry ([DATA-FLOW.md](../architecture/DATA-FLOW.md) §3); `fatoora:verify-hash-chain` |
| No issued Saudi document is removed or reordered unseen | ICV and PIH chain per device, written in the issuing transaction; unique `(device_id, icv)` and one chain row per invoice | `fatoora:verify-hash-chain` (weekly, and on demand with `--organization=`); [sa/ICV-PIH.md](sa/ICV-PIH.md) |
| Compliance records are never deleted | Invoices, lines, submissions, state logs, chain, network documents, payments and the audit log have no `deleted_at`; organizations with documents cannot be deleted | Table classes in `tests/Feature/Architecture/TableTimestamps.php`, enforced by `TableTimestampsTest` |
| A status reflects the authority's verdict only | Allowed-transitions tables, one writer per record; an outage or a 401 never marks an invoice accepted or rejected | [FILING-STATES.md](../architecture/FILING-STATES.md); `submission_state_logs` |
| The cleared copy is the legal standard invoice | ZATCA's stamped XML kept in `invoices.cleared_xml` and served in preference | `invoices.cleared_xml`, `zatca_response` |
| Documents conform to the authority's format | ZATCA SDK validation, PINT AE schematron and a PDF/A validator run in CI over generated documents; golden-master documents must stay byte-identical | [sa/STATUS.md](sa/STATUS.md); CI results |
| Every action is attributable | Append-only `audit_logs` (the model refuses update and delete); actor, channel and licence columns on records; a request id across logs, records and jobs | [AUDIT-SCHEMA.md](../architecture/AUDIT-SCHEMA.md) |
| Tenants are isolated | Every tenant row carries `org_id` and a global scope; raw queries on tenant tables are a reviewed, test-held list | [TENANT-DATABASES.md](../architecture/TENANT-DATABASES.md) |
| Signing keys are protected | Encrypted at rest, never logged, tagged with the ZATCA environment that issued them; an expired certificate cannot stamp. A document stamped before its certificate expired is still filed with that version, unless the organization's current production certificate is itself expired ([POLICIES.md](POLICIES.md) §8) | [OVERVIEW.md](../architecture/OVERVIEW.md); [sa/HASHING-AND-SIGNING.md](sa/HASHING-AND-SIGNING.md) |
| Records are retained | Nothing is deleted by the platform; partitions are created ahead and never detached on the schedule; yearly backups kept six years | [POLICIES.md](POLICIES.md) (retention); `config/backup.php` |

<!-- Invoice::booted (updating guard); AuditLog::booted; database/migrations/0270_device_chains.php;
     routes/console.php (fatoora:verify-hash-chain weekly; compliance:partition-maintenance --create-future) -->

## 2. Access control and segregation of duties

- **Organization roles** (`MemberRole`): owner and admin run the organization;
  member invoices; accountant reads, reports, exports and keeps the books but
  issues nothing; viewer reads. Branch access narrows a person to their
  branches.
- **Feature access** is one decision per organization, role and country,
  with a stated reason when refused ([ACCESS.md](../architecture/ACCESS.md)).
- **Maker-checker.** Administrative actions under an approval policy become a
  request with a reason, a before/after preview and an expiry. Nobody
  approves their own request or a change to their own access, and the
  requester must still hold the right when it runs
  ([APPROVALS.md](../architecture/APPROVALS.md)). Held as shipped: admin
  access, marking a certificate revoked, and switching a Saudi
  organization's ZATCA environment to or from production.
- **Notes.** An organization can require a second person for credit and debit
  notes (`approvals.notes`, off by default, optionally above a total): a note
  from the portal, the API or the pipeline waits until another owner or
  administrator approves it, and every check runs again when it is issued.
  With nobody else who could approve, it is issued and recorded as
  `approval.bypassed`. Notes the platform issues itself (billing refunds,
  settled insurer splits) are not held
  ([APPROVALS.md](../architecture/APPROVALS.md#organization-approvals)).
- **Platform administrators** hold named permissions per console area
  (`admin.can:*`), and every console change is written to the audit log
  (`admin.audit` middleware).
- **Emergency stop.** A kill switch halts submission, clearance, reporting or
  issuance, globally or per organization; enabling it requires a reason and is
  logged at critical level.

## 3. Exports an auditor can ask for

| What | Where |
|---|---|
| Invoices and notes (CSV) | Portal › Invoices export |
| Each invoice as PDF | Portal invoice page; tenant API `GET /invoices/{invoice}/pdf` |
| A UAE network document's XML | Tenant API `GET .../documents/{document}/xml` |
| VAT report, daily close, invoice report (CSV) | Portal › Reports |
| VAT return draft (PDF), receivables, statements, purchases | Portal › Books |
| The organization's activity (CSV) | Portal › Activity (administrators) |
| The whole audit trail, all tenants (CSV) | Admin › Audit (`audit.view` permission) |
| Saudi submission history | Admin console and portal submission lists |

Exports are rate limited and scoped to the organization asking.

## 4. Verification commands

| Command | Checks |
|---|---|
| `php artisan fatoora:verify-hash-chain [--organization=ID]` | Every device's chain: each document's PIH against the previous hash, and the recorded chain against what the stored rows derive |
| `php artisan fatoora:check-certificate` | Certificate expiry per device |
| `php artisan fatoora:check-revocation` | Certificate revocation |
| `php artisan fatoora:overdue-submissions` | Simplified invoices past the 24-hour reporting deadline |
| `php artisan compliance:index-health` | Index health of the critical compliance tables |

The SQL in [DATA-FLOW.md](../architecture/DATA-FLOW.md) §3 and
[AUDIT-SCHEMA.md](../architecture/AUDIT-SCHEMA.md) §3 and §5 answers the
usual questions directly from the database.

## 5. What the platform does not claim

- It does not decide taxability, rates or registration.
- `audit_logs` is an application record, not cryptographic proof; the
  tamper-evidence is the document hash chain.
- For the UAE, transport and authority reporting are the accredited service
  provider's; the platform keeps the document and every status the provider
  returns ([ae/README.md](ae/README.md)).
- What has and has not been proven against the Saudi authority is in
  [sa/STATUS.md](sa/STATUS.md).

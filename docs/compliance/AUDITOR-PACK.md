# Auditor pack

Audience: an external auditor and their technical advisor. What the system
guarantees, the evidence for each guarantee, and how to check it yourself.
Detail is linked, not repeated.

## 1. Guarantees and where the evidence is

| Guarantee | How it is enforced | Evidence / how to check |
|---|---|---|
| An issued invoice never changes | Once a document leaves draft the model refuses any change to it, its lines, charges and prepayments, and on MySQL database triggers refuse the same for a direct `UPDATE`/`DELETE` (migration `1814`). Only `status`, `zatca_response` and `updated_at` move after issue; the stamp (`hash`, `qr_code`, `signed_xml`, `cleared_xml`) is written once, and `qr_code` is replaced once, by ZATCA's, when the cleared copy arrives (`Invoice::MUTABLE_AFTER_FINALIZED`, `SET_ONCE_AFTER_FINALIZED`). Corrections are credit or debit notes, which in Saudi Arabia need an original the authority has accepted (IR Art 54(4), BR-KSA-56), and credit notes together never exceed what their originals charged | `fatoora:verify-hash-chain` recomputes each hash from the stored signed XML; `FrozenInvoiceDatabaseTest` (MySQL); [DATA-FLOW.md](../architecture/DATA-FLOW.md) §3 |
| No issued Saudi document is removed or reordered unseen | ICV and PIH chain per device, written in the issuing transaction; unique `(device_id, icv)` and one chain row per invoice | `fatoora:verify-hash-chain` (weekly, and on demand with `--organization=`): counter gaps, PIH against the previous hash, each stored hash recomputed from `signed_xml` (C14N as the signer), and the ICV and PIH inside the signed XML against the chain; [sa/ICV-PIH.md](sa/ICV-PIH.md) |
| Compliance records are never deleted | Invoices, lines, submissions, state logs, chain, network documents, payments and the audit log have no `deleted_at`; organizations with documents cannot be deleted | Table classes in `tests/Feature/Architecture/TableTimestamps.php`, enforced by `TableTimestampsTest` |
| A status reflects the authority's verdict only | Allowed-transitions tables, one writer per record; an outage or a 401 never marks an invoice accepted or rejected | [FILING-STATES.md](../architecture/FILING-STATES.md); `submission_state_logs` |
| The cleared copy is the legal standard invoice | ZATCA's stamped XML kept in `invoices.cleared_xml` and served in preference | `invoices.cleared_xml`, `zatca_response` |
| Documents conform to the authority's format | ZATCA SDK validation, PINT AE schematron and a PDF/A validator run in CI over generated documents; golden-master documents must stay byte-identical | [sa/STATUS.md](sa/STATUS.md); CI results |
| Every action is attributable, and the record is not rewritten | Append-only `audit_logs`: the model refuses update and delete, MySQL triggers refuse them too (migration `1817`), and deleting a user or organization an entry names is refused rather than blanking it (`1816`). Each entry is chained per organization: its sequence, its predecessor's hash and a SHA-256 of its own content (`AuditChain`). With `AUDIT_CHAIN_KEY` (its own key, never `APP_KEY`) each new entry and each chain's head also carry an HMAC-SHA256 seal, so someone with database access but not the key cannot rewrite an entry and recompute the chain unseen; on MySQL a chain's head only moves forward and is never deleted (migration `1851`). Issuance and its audit entry commit together. Actor, channel and licence columns on records; a request id across logs, records and jobs | `php artisan platform:verify-audit-chain [--organization=ID]` (weekly); [AUDIT-SCHEMA.md](../architecture/AUDIT-SCHEMA.md) |
| Tenants are isolated | Every tenant row carries `org_id` and a global scope; raw queries on tenant tables are a reviewed, test-held list | [TENANT-DATABASES.md](../architecture/TENANT-DATABASES.md) |
| Signing keys are protected | Encrypted at rest, never logged, tagged with the ZATCA environment that issued them; an expired certificate cannot stamp. A document stamped before its certificate expired is still filed with that version, unless the organization's current production certificate is itself expired ([POLICIES.md](POLICIES.md) §8). In ZATCA production a certificate no current revocation list vouches for cannot stamp either (the document stays a draft, the alert channel is told) and the off switch is ignored; sandbox and simulation stamp with a logged warning | [OVERVIEW.md](../architecture/OVERVIEW.md); [sa/HASHING-AND-SIGNING.md](sa/HASHING-AND-SIGNING.md) |
| Records are retained | Nothing is deleted by the platform; partitions are created ahead and never detached on the schedule; yearly backups kept fifteen years | [POLICIES.md](POLICIES.md) (retention); `config/backup.php` |

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
  notes (`approvals.notes`, on for a new organization while those from
  before keep their setting, optionally above a total): a note from the portal,
  the API or the pipeline waits until another owner or administrator
  approves it, and every check runs again when it is issued. With nobody
  else who could approve, it still waits - its `approval.requested` record
  says `no_other_approver` - and the owners and admins are emailed; nothing
  is bypassed. Notes the platform issues itself (billing refunds,
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
| Saudi e-invoice archive (ZIP): signed and cleared XML, PDFs, `manifest.csv` (number, UUID, ICV, hash, PIH, status, ZATCA's answer) and `SHA256SUMS`, files named `VAT_YYYYMMDDTHHMMSS_IRN` (XML Implementation Standard v1.2 §14) | Portal › Reports (owners, administrators, accountant; 31 days at a time); Admin › ZATCA environment (a year); `php artisan fatoora:export-archive {organization} --from= --to=` (any period). Each export is on the activity record |

Exports are rate limited and scoped to the organization asking.

## 4. Verification commands

| Command | Checks |
|---|---|
| `php artisan fatoora:verify-hash-chain [--organization=ID]` | Every device's chain: each document's PIH against the previous hash, each stored hash recomputed from its signed XML, and the ICV and PIH the signed XML carries |
| `php artisan platform:verify-audit-chain [--organization=ID]` | Every audit chain: sequence gaps, broken links, entries whose content no longer matches their hash, entries cut off the end |
| `sha256sum -c SHA256SUMS` (inside an archive) | Every file in an exported e-invoice archive |
| `php artisan fatoora:check-certificate` | Certificate expiry per device |
| `php artisan fatoora:check-revocation` | Certificate revocation |
| `php artisan fatoora:overdue-submissions` | Simplified invoices past the 24-hour reporting deadline |
| `php artisan compliance:index-health` | Index health of the critical compliance tables |

The SQL in [DATA-FLOW.md](../architecture/DATA-FLOW.md) §3 and
[AUDIT-SCHEMA.md](../architecture/AUDIT-SCHEMA.md) §3 and §5 answers the
usual questions directly from the database.

## 5. What the platform does not claim

- It does not decide taxability, rates or registration.
- The audit chain shows that entries were changed, removed or cut off; it
  cannot prove the entries written before the chain was introduced were not
  changed before then (migration `1815` sealed them as they stood). Entries
  written before `AUDIT_CHAIN_KEY` was set carry no keyed seal and rest on
  their hash; from a chain's first sealed entry every later one must be
  sealed. The seal is not anchored outside the platform: someone holding both
  the database and the key could still rewrite it. Anchoring the heads
  outside (an emailed or published digest, a timestamping service) is an
  owner's decision and is not built.
- `platform:simulation-preflight` checks that every immutability trigger
  exists (`ImmutabilityTriggers`) and that the application's database account
  cannot drop or create triggers or truncate tables.
- The database triggers exist on MySQL only; on SQLite (tests) the model
  guards hold the same rules. Triggers do not see `TRUNCATE`, a dropped
  trigger or a schema change. With one database account that both migrates
  and serves, that account can do those things, so the guarantee is the
  triggers plus the audit and hash chains, which show what such a change did.
  With a separate migration account (`DB_MIGRATION_USERNAME`) and an
  application account holding only `SELECT`, `INSERT`, `UPDATE` and `DELETE`,
  nothing that runs as the application can remove a trigger
  ([DEPLOYMENT.md](../operations/DEPLOYMENT.md#the-database-account-that-migrates)).
- For the UAE, transport and authority reporting are the accredited service
  provider's; the platform keeps the document and every status the provider
  returns ([ae/README.md](ae/README.md)).
- What has and has not been proven against the Saudi authority is in
  [sa/STATUS.md](sa/STATUS.md).

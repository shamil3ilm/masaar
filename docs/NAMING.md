# Naming conventions

One rule underlies all of these: **a name should not repeat what its location
already tells you, and should be readable at a glance.**
`app/Domains/Auth/Http/Middleware/EnsureUserIsAdmin` said "Auth" twice and
"Ensure" for no reason. It is now `IsAdmin`.

`tests/Feature/Architecture/NamingConventionTest.php` enforces the mechanical
parts of this document. If you change a convention, change that test with it.

---

## Domain vocabulary is exempt

**Never** rename these to plainer English:

`ZATCA` · `Fatoora` · `FTA` · `Peppol` · `ICV` · `PIH` · `CSID` · `CCSID` ·
`PCSID` · `XAdES` · `TLV` · `UBL` · `CRL` · `OCSP` · `QR` · `VAT` · `CR`

They are the words used by the regulators and their specifications. An
engineer holding the ZATCA spec next to the code needs `IcvManager` and
`TlvEncoder` to be findable. Renaming `TlvEncoder` to `QrFieldEncoder` makes
the code read more easily to a newcomer and much harder to audit against the
specification, which is the trade we do not want.

---

## Classes

| Kind | Convention | Examples |
|---|---|---|
| Middleware | Mirrors the route alias. No `Ensure`/`Validate`/`Restrict`/`Resolve` prefix. | `IsAdmin` (`admin`), `JwtGuard` (`jwt.auth`), `PortalTenant` (`portal.tenant`) |
| Controller | `<Noun>Controller` | `InvoiceController`, `AdminController` |
| Form request | `<Verb><Noun>Request` | `CreateInvoiceRequest` |
| Model | Singular noun | `Invoice`, `Branch`, `License` |
| Enum | Singular noun, no `Enum` suffix | `InvoiceStatus`, `DocumentType` |
| DTO | `<Noun>Data` | `CsrData`, `AddressData` |
| Job | Imperative verb phrase | `ProcessFatooraSubmission` |
| Console command | Imperative verb phrase, **no** `Command` suffix | `ProcessOfflineQueue`, `CheckExpiredLicenses` |
| Exception | `<Context>Exception` | `CertificateException` |
| Service | Agent noun where one fits, else `<Noun>Service` | `XadesSigner`, `InvoiceHasher`, `TlvEncoder`, `CertificateService` |

Prefer an agent noun (`Signer`, `Hasher`, `Encoder`, `Builder`, `Validator`,
`Submitter`, `Tracker`) over `<Thing>Service`. It says what the class *does*
rather than only what it is about. `Manager` and `Handler` say nothing — reach
for them last.

### Do not repeat the enclosing namespace

`Domains/Compliance/Fatoora/Services/FatooraSubmissionService` says "Fatoora"
twice and "Service" once more than needed. The exception is a class referred to
from *outside* its domain, where the prefix is what makes the import readable —
`FatooraException` and `InvoiceStatus` keep theirs deliberately.

---

## Methods

- Booleans read as assertions: `isActive()`, `hasScope()`, `canRetry()`.
- Do not repeat the class's own noun. On `CertificateLineageService`,
  `validateCertificateForSigning()` became `validateForSigning()`, and
  `getInvoicesSignedWithCertificate()` became `getSignedInvoices()`.
- Private helpers get the shortest name that stays clear in context.
- Keep `get` only where it reads better than the bare noun.

---

## Tests

The method name identifies the case; the docblock carries the reasoning.

```php
// too long - this is a sentence
public function test_organization_admin_cannot_reach_the_admin_api(): void

// says the same thing
public function test_org_admin_denied(): void
```

Name the outcome — `test_guest_denied`, `test_valid_login`,
`test_duplicate_icv_rejected` — and put the *why* in the docblock above it,
where it does not have to fit on one line.

**Cap: six words**, enforced by `NamingConventionTest::test_names_stay_short`.
Three quarters of the suite already sits at two to four. If a name needs a
seventh word, it is describing the reasoning rather than the outcome, and the
reasoning belongs in the docblock.

---

## Database

Tables are `snake_case` plural (`invoices`, `invoice_submissions`); pivots are
the two singulars alphabetically (`organization_user`); columns are
`snake_case`, foreign keys are `<singular>_id`.

Table names are **not** renamed casually — but the reason is not cross-repo
coupling. `erp-backend` reaches this platform over HTTP
(`config/zatca-integration.php` → `/api/v1`) and defines no connection to this
database, so a rename here breaks nothing in that repository.

The real constraint is that a rename costs a migration, a coordinated deploy,
and a window where old and new code disagree about the table — so it has to buy
something. Two that do:

| Current | Rename to | Why |
|---|---|---|
| `environment_variance_log` | `variance_logs` | Only singular table name in the schema; `environment_` restates the column it holds |
| `uae_fta_submissions` | `fta_submissions` | The API moved to ISO codes (`/compliance/ae/`) and the `uae_fta` config key is already gone |

The `license_*` cluster — eight tables — is the one that reads worst, and it is
the one **not** to rename yet. `licenses`, `license_registrations` and
`organization_licenses` are hard to tell apart because the *concepts* overlap:
three licensing systems occupy one namespace. Renaming would give the confusion
better labels. Collapse the concepts first, rename once afterwards. See the
licensing consolidation in `docs/audit/05-TARGET-ARCHITECTURE-AND-ROADMAP.md`.

---

## Migration filenames

Keep the timestamps. They look redundant next to a `0010_`-style sequence, but
they are load-bearing:

- Laravel stores the **filename** in the `migrations` table. Renaming a file
  that has already run makes every existing database believe it never ran, so
  the next `migrate` re-runs it — duplicate column and table errors, against
  live data.
- Timestamps stop branches colliding. Two people adding a migration the same
  week get distinct names automatically; two people both reaching for `0042_`
  do not.
- `make:migration` generates timestamps, so a sequence has to be maintained by
  hand forever.

The real problem a sequence is reaching for — 36 files with three different
date conventions — is solved by squashing instead: `php artisan schema:dump
--prune` collapses them into one `database/schema/` file and deletes the
originals. Existing databases keep working, because Laravel loads the schema
file and then applies only the migrations dated after it.

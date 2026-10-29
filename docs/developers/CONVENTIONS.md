# Conventions

Audience: engineers changing code in this repository.

Most rules below are held by a test in `tests/Feature/Architecture`; the test
named beside a rule is the one that fails when it is broken. What each test
checks is listed in [TESTING-AND-GATES.md](TESTING-AND-GATES.md). Never loosen
a ratchet to make a change pass: put the behaviour where the ratchet allows it.

## Naming

A name does not repeat what its location already says, and reads at a glance.
`NamingConventionTest` holds the mechanical parts; change it with this section.

| Kind | Convention | Examples |
|---|---|---|
| Middleware | Mirrors its route alias; no `Ensure`, `Restrict` or `Resolve` prefix | `JwtGuard` (`jwt.auth`), `PortalTenant` (`portal.tenant`), `IsOrgAdmin` (`org.admin`) |
| Controller | `<Noun>Controller` | `InvoiceController` |
| Form request | `<Verb><Noun>Request` | `CreateInvoiceRequest` |
| Model | Singular noun | `Invoice`, `Branch`, `License` |
| Enum | Singular noun, no `Enum` suffix | `InvoiceStatus`, `DocumentType` |
| DTO | `<Noun>Data` or a plain noun, `readonly` | `CsrData`, `AddressData` |
| Job | Imperative verb phrase | `ProcessFatooraSubmission` |
| Console command | Imperative verb phrase, no `Command` suffix | `ProcessOfflineQueue`, `CheckExpiredLicenses` |
| Exception | `<Context>Exception` | `CertificateException` |
| Service | An agent noun where one fits | `XadesSigner`, `InvoiceHasher`, `TlvEncoder` |

- Prefer `Signer`, `Hasher`, `Builder`, `Validator` over `<Thing>Service`;
  `Manager` and `Handler` say nothing.
- Do not repeat the namespace (`Countries/SaudiArabia/Filing/SaudiArabiaFilingService`).
  A class used from outside its domain may keep its prefix (`FatooraException`).
- Methods: booleans read as assertions (`isActive()`, `canRetry()`); do not
  repeat the class's own noun.
- `app/` holds only `Console`, `Domains`, `Http`, `Logging`, `Providers` and
  `Support`: models, services, DTOs, jobs and policies live under
  `app/Domains/<Domain>/`, never in top-level folders of their own.
- Tables are plural `snake_case`, pivots are the two singulars in alphabetical
  order, foreign keys `<singular>_id`. Renaming a table costs a migration and a
  coordinated deploy, so only rename when it buys something.

**Domain vocabulary is exempt.** Keep the regulators' words so the code can be
read against the specification: `ZATCA` · `Fatoora` · `Peppol` · `PINT` ·
`ASP` · `TRN` · `TIN` · `ICV` · `PIH` · `CSID` · `CCSID` · `PCSID` · `XAdES` ·
`TLV` · `UBL` · `CRL` · `OCSP` · `QR` · `VAT` · `CR`. UAE code is named after
the specification (`PintMapper`, `PintAe`, `MandatoryFields`) or the country
(`UaeVatSummary`). Every country module uses the same entry points
(`Module`, `ModuleServiceProvider`, `Issuing\IssuingStep`,
`Issuing\InvoiceIssuer`); a network module names no country
(`Networks\Peppol\NetworkServiceProvider`, `PeppolSender`).

## Provider-neutral names and the brand

- No vendor, partner or provider name in env keys, config, code, tests or docs.
  Name by purpose: an accredited service provider is `Asp` (`PEPPOL_ASP_*`,
  `AspGateway`); a simulator is selected by `PEPPOL_GATEWAY=simulator` or
  `PAYMENT_GATEWAY=simulator`. `PublicDocsTest` scans the docs.
- The product name is written once, in `config/brand.php`. Code reads
  `App\Support\Brand` or `config('app.name')`; translated lines use the `:app`
  placeholder. `BrandNameTest` fails on the name anywhere else.

## Code style

- `vendor/bin/pint` on the files you changed (the Laravel preset; CI runs
  `vendor/bin/pint --test`). On Windows with `core.autocrlf`, a local
  `pint --test` can report line endings that are not wrong; trust the fix run.
- `declare(strict_types=1);`, thin controllers, rules in services, `readonly`
  DTOs, dependencies in constructors (`ServiceLocatorTest`), no queries in
  controllers (`ControllerQueryTest`), no shelling out on request or queue
  paths (`NoShellOutTest`).
- Match the surrounding comment density: a docblock says why, citing the rule
  (ZATCA standard or BR-KSA id, PINT AE rule id, UBL 2.1, EN 16931) for any
  compliance behaviour.

## Domain boundaries

How the engine, countries and networks fit together is in
[JURISDICTION-ROUTING.md](../architecture/JURISDICTION-ROUTING.md). The rules
in code:

- Shared code never asks which country it is: it asks the compliance engine
  through a port (`CountryCheckTest`, held at zero; `NeutralPanelsTest`).
- A country module never names another country; a network names none; the
  engine names no module (`ModuleBoundaryTest`).
- A domain's models are its own (`CrossDomainModelTest`).
- Tenancy: a tenant model's queries are held to the request's tenant, and a
  request with none matches nothing. A query that must read across tenants
  says so itself, `Model::acrossTenants()` or `->acrossTenants()`, for that
  query only (`TenantBypassTest` counts them). Work for one organization in a
  command or job runs inside `TenantResolver::runAs()`. Raw `DB::table()` on a
  tenant table only in a file `RawTenantQueryTest` lists. The rules:
  [DOMAINS.md](../architecture/DOMAINS.md#rules-that-hold-across-domains).
- One country's behaviour does not leak into the other's screens, API or
  payloads (`tests/Feature/Compliance/JurisdictionIsolationTest.php`).

## Strings and screens

- Every user-facing string goes through `__()` and has Modern Standard Arabic
  in `lang/ar.json`, with the same placeholders (`TranslationTest`).
- Screens use the design-system components in `resources/views/components/ui`
  (`<x-ui.button>`, `<x-ui.table>`, `<x-ui.field>` ...) and colour tokens only.
  `DesignSystemTest` holds hex colours and stock palette classes at zero and
  hand-written controls at two. The live style guide is `/admin/design-system`.
- Lists use `<x-ui.table>` with declared compact-row columns (`:primary`) for
  phones (`CompactRowsTest`); optional parts of a form use `<x-ui.section>`.

## Tables: timestamps and attribution

Every table is classified in `tests/Feature/Architecture/TableTimestamps.php`
and `TableTimestampsTest` checks the migrated schema against it:

| Class | What it is | Keeps |
|---|---|---|
| a | Mutable entity | `created_at`, `updated_at`, `created_by`, `updated_by` |
| b | Entity a user may remove | as (a) plus `deleted_at` (SoftDeletes) and `deleted_by` |
| c | Compliance or financial record | `created_at` (+ `updated_at` only if its status changes); never `deleted_at` |
| d | Append-only log | `created_at` or a named event time; no `updated_at` |

Tenant-owned tables carry `org_id` (and `branch_id` where it applies).
Records that arrive several ways carry `created_via`
(`App\Domains\Audit\Attribution\Channel`) and `license_id`. Never set actor or
channel columns by hand or make them fillable: use the `RecordsActors` concern
(`App\Domains\Organization\Concerns\RecordsActors`), or
`Origin::columns([...])` (`App\Domains\Audit\Attribution\Origin`, injected) for
a query-builder write. Store user ids, never copies of names.

## Migrations

- Files in `database/migrations` are `NNNN_what_it_does.php`: take the next number
  after the highest one there. One concern per migration, with a working
  `down()`.
- Write columns as literal calls (`$table->string('reference', 100)`), not in
  loops or from variables: `AttributeReadTest` reads the migrations to learn
  which columns exist.
- Add the table to `TableTimestamps.php`, then regenerate the schema reference
  with `php artisan platform:schema` (`SchemaDriftTest` compares
  `docs/schema.sql`).

## Settings

Every customization has a shipped default, a platform value and organization
and per-person overrides, declared once in
`App\Domains\Platform\Customization\Catalogue`; see
[CUSTOMIZATION.md](../architecture/CUSTOMIZATION.md). An operational number is
a setting, and a setting is read by something (`SettingUseTest`,
`ConfigReaderTest`, `EnvExampleTest`).

## Money and time

- Amounts are `decimal` columns and decimal strings in PHP, calculated with
  `bcmath` (`InvoiceTotals`), never floats.
- An instant (signed at, retry due) is UTC. A civil date (the date on a
  document, the period a counter restarts) is read on the organization's
  country clock: `App\Support\CountryClock::now($country)`.
- Carbon 3 `diffIn*()` is signed. SQLite hides a negative that MySQL rejects,
  so take the absolute value or order the operands deliberately.
- MySQL reorders the keys of a JSON column: compare JSON by key, not as a
  string.

## Tests

- A test name states the outcome in at most six words after `test_`
  (`NamingConventionTest::test_names_stay_short`); the reasoning goes in the
  docblock: `test_duplicate_icv_rejected`, not a sentence.
- Write the failing test first. Golden masters are never regenerated without
  the owner's approval ([TESTING-AND-GATES.md](TESTING-AND-GATES.md)).

## Commits

Conventional commits, `<type>(<scope>): <what changed>`, for example
`fix(invoice): name buyer fields in plain words in the form's errors`. Types:
`feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`. Put
`[sandbox]` in the message to run the ZATCA sandbox round trip on that push.

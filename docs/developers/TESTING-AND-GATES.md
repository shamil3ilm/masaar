# Testing and gates

Audience: engineers running the suites locally and reading CI results.

## The suite

`phpunit.xml` declares two suites, `Unit` and `Feature`, on in-memory SQLite.
It forces `APP_ENV=testing`, `APP_DEBUG=false` and `ZATCA_ENVIRONMENT=sandbox`,
so a developer's `.env` cannot change what a test sees.

```bash
php artisan test --parallel --processes=6      # everything, SQLite
php artisan test tests/Feature/Architecture    # the ratchets only
php artisan test --filter=InvoiceTotalsTest    # one class or method
```

`public/build` must exist (`npm run build`): screens render `@vite`.

**MySQL.** CI runs the same suite on MySQL 8.4, which catches what SQLite
lets through (a `WHERE` on a missing column, foreign-key types). Set the
connection in the environment, not in `phpunit.xml`:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD= \
DB_DATABASE=platform_test php artisan test --parallel --processes=6
```

In PowerShell set each with `$env:DB_CONNECTION = 'mysql'` and so on. With
`--parallel` each process gets its own copy of the database (`<name>_test_<n>`),
so the user needs permission to create databases. Both SQLite and MySQL must be
green before a merge.

## Architecture tests

`tests/Feature/Architecture` reads the whole codebase. A ceiling or allow-list
in one of these only ever shrinks; never raise it to make a change pass. The
equality ratchets (`CrossDomainModelTest`, `TenantBypassTest`) also fail when
a count drops, so lower them in the change that removes one; one that grows
is raised only in the change that needs it, with its reason, where a
reviewer sees it.

| Test | Holds |
|---|---|
| `ActionMarkupTest` | Actions are big enough to hit and every control has an accessible name |
| `AdminRoutePermissionTest` | Every admin route names the permission it needs |
| `AttributeReadTest` | Every `->snake_case` read is answered by a column, accessor, relation or cast |
| `BadgeColourTest` | A state has one colour, decided in one place |
| `BrandNameTest` | The product name appears only in `config/brand.php` |
| `BrowserDrawnTest` | Native browser widgets are styled by the design system |
| `ClassReferenceTest` | Every class a file names resolves |
| `CommandConstructionTest` | Console commands take domain services from the container |
| `CompactRowsTest` | Lists collapse to key fields on a phone through `x-ui.table` |
| `ComponentUseTest` | Messages, dates, money and labelled controls use their one component |
| `ComposeEnvTest` | The containers receive the settings a deployment is told to set |
| `ComposeExposureTest` | What the compose files expose on the network |
| `ConfigKeyTest` | No config array declares a key twice |
| `ConfigReaderTest` | Every documented setting is read by something |
| `ConfirmationMarkupTest` | Destructive and changing actions confirm the same way |
| `ConnectionNameTest` | No code names a database connection that configuration chooses |
| `ContainerUserTest` | The image starts as a user that can do what its init needs |
| `ControllerQueryTest` | No persistence in controllers |
| `CountryCheckTest` | Shared code never asks which country it is (held at zero) |
| `CredentialIsolationTest` | Tests cannot reach a developer's real signing credentials |
| `CrossDomainModelTest` | Each domain's models stay its own |
| `DeploymentTemplateTest` | The deployment template names what a deployment must supply, and boots |
| `DesignSystemTest` | Hex colours 0, stock palette 0, hand-written controls 2 |
| `DocLinkTest` | Every relative link in the docs points at a file that exists |
| `DocsReferenceTest` | The artisan commands, settings and paths the docs name exist; docs carry no dates |
| `DockerEnvironmentTest` | Every setting the containers pass is one the app reads |
| `DockerRuntimeVersionTest` | Runtime versions agree across the Dockerfile, `composer.json` and CI |
| `EnvExampleTest` | Every setting the app reads is in `.env.example` |
| `EnvFallbackTest` | The environment name has the same fallback everywhere |
| `ErrorEnvelopeTest` | Every API error leaves through the same envelope |
| `ErrorTaxonomyTest` | Every declared error code is answered |
| `FlashKeyTest` | Every flashed message is rendered by some page |
| `InstallTest` | `composer install` works before a `.env` exists |
| `LogRedactionTest` | Secrets never reach a log channel |
| `MessageStyleTest` | One message per response, in the house wording |
| `MiddlewareAliasTest` | Every middleware alias is attached to something |
| `ModelColumnTest` | A model declares only fields its table has |
| `ModuleBoundaryTest` | Modules name no other country, networks no country, the engine no module; shared reach-ins only shrink |
| `NamingConventionTest` | The naming rules in [CONVENTIONS.md](CONVENTIONS.md), test names of at most six words |
| `NeutralPanelsTest` | Shared screens name no authority or network |
| `NginxPostureTest` | Deny rules and security headers in the nginx config |
| `NoShellOutTest` | No shelled-out commands on request or queue paths |
| `OpenSslLookupTest` | One place decides where OpenSSL is |
| `OpenapiDriftTest` | `docs/openapi.yaml` equals the route table |
| `ProductionGuardTest` | The deployment template passes the production boot checks |
| `PublicDocsTest` | No dates, partner names or internal references in public docs |
| `RawTenantQueryTest` | Raw access to tenant tables is deliberate and listed |
| `RelationKeyTest` | Pins foreign keys Eloquent derives for shortened columns |
| `ReplicaPostureTest` | What a read replica may and may not serve |
| `ResponsiveLayoutTest` | Navigation leaves room for the page on small screens |
| `RouteShadowTest` | A fixed path is not swallowed by a parameter route above it |
| `ScheduledCommandTest` | Scheduled commands exist, run on one server and state their overlap expiry |
| `SchemaDriftTest` | `docs/schema.sql` equals the migrated schema |
| `ScreenRenderTest` | Every admin and portal screen renders, with rows, one header |
| `ServiceLocatorTest` | Domain services take dependencies in their constructors |
| `SettingUseTest` | An operational number is a setting, and every setting is read |
| `TableMarkupTest` | Tables read on a phone and mark their headings |
| `TableTimestampsTest` | Each table keeps what its class in `TableTimestamps.php` requires |
| `TenantBypassTest` | Queries that read across tenants (`acrossTenants()`) are counted by domain; the callback form stays gone |
| `TranslationTest` | Every `__()` string has Arabic, placeholders intact, no duplicate keys |
| `WebhookHeaderDocsTest` | The docs name the webhook header the platform sends |
| `WiredDependencyTest` | A capability that is written is also reached |
| `WorkflowSyntaxTest` | Every CI workflow parses |

Also outside that folder: `tests/Feature/Compliance/JurisdictionIsolationTest.php`
(one country's rules never reach the other's organizations) and
`tests/Feature/Security` (unguarded routes, tenant scoping, the admin API).

When one fails, its message says what to add or regenerate:
`php artisan platform:openapi` for `OpenapiDriftTest`,
`php artisan platform:schema` for `SchemaDriftTest`.

## Golden masters

`tests/Feature/Compliance/GoldenMaster/SaudiIssuingGoldenTest.php` compares
what Saudi issuing produces, byte for byte, with snapshots in
`tests/Fixtures/golden/` (time, ids and test keys pinned; only the ECDSA
signature is masked and verified instead). A difference means issuing output
changed.

**Never regenerate a snapshot (`GOLDEN_UPDATE=1`) without the owner's
approval.** For new behaviour, add a new golden case; do not rewrite an old
one to match.

## Conformance suites

Each runs the authority's or the specification's own validator over documents
the platform built. Without its tool a suite skips, which proves nothing; CI
fails on a skip.

| Suite | Tool | Point at it with |
|---|---|---|
| ZATCA (`ZatcaConformanceTest` and the other `tests/Feature/Compliance/SaudiArabia/*ConformanceTest`) | ZATCA's SDK, unpacked (needs Java 17) | `ZATCA_SDK_PATH` = the folder holding `Apps/` and `Data/` |
| PINT AE (`PintConformanceTest`, `SimulatorConformanceTest`) | The official PINT AE billing and self-billing artefacts plus an XSLT 2 processor jar | `PEPPOL_ARTEFACTS_PATH` = a folder with `billing/`, `selfbilling/` and `ubl21/` unpacked; `PEPPOL_XSLT_JAR` = the jar |
| PDF/A (`tests/Feature/Invoice/PdfaConformanceTest.php`) | A PDF/A-3b validator's command-line launcher | `PDFA_VALIDATOR_PATH` |
| Code lists (`tests/Feature/Compliance/CodeListConformanceTest.php`) | The Saudi lists need the SDK, the UAE lists the PINT AE artefacts | as above |

Set the variables in the shell or in `.env`, then for example
`php artisan test --filter=ZatcaConformance`. To judge a run the way CI does
(fail on a skip, on nothing run, or on a missing class):

```bash
bash scripts/ci/run-conformance.sh PdfaConformance PdfaConformanceTest
```

Two traps with ZATCA's SDK: do not run its `install.bat`, which rebuilds
the Windows `PATH` from a copy cut at 1024 characters (the test sets the two
variables the SDK reads for itself); and moving the SDK after unpacking
leaves absolute paths in its `Configuration/config.json`, so every document
then fails until they are repointed.

The `conformance`, `pint-ae` and `pdfa` jobs in `ci.yml` show the exact
filters and where each tool is downloaded from.

## End-to-end run

`php tests/E2e/run.php` boots the app and drives real HTTP through the kernel
against the database in `.env` (MySQL in CI): sign-in, tenant isolation,
issuing up to the ZATCA network boundary. It is not a PHPUnit test and writes
real rows, so point it at a throwaway, migrated database. It exits non-zero on
the first failure.

## CI (`.github/workflows/ci.yml`)

| Job | Runs | When |
|---|---|---|
| What changed | Classifies the push as app and/or container changes | every push and PR |
| Tests | SQLite suite, after `npm ci && npm run build` | always |
| MySQL (suite and end-to-end) | Migrations, `tests/E2e/run.php`, a backup and restore drill, then the suite on MySQL | app changes |
| Formatting | `vendor/bin/pint --test` | always |
| ZATCA conformance | SDK suites via `run-conformance.sh` (warns when the SDK secret is not set) | app changes |
| PDF/A conformance | Installs a signature-checked validator, runs the PDF/A suite | app changes |
| PINT AE conformance | Hash-pinned PINT AE artefacts and XSLT jar, runs the PINT suites | app changes |
| ZATCA sandbox round trip | `fatoora:generate-csr`, `fatoora:onboard --step=full` then `--step=submit`, `fatoora:sandbox-filing` | nightly, by hand, or a push whose commit message contains `[sandbox]` |
| Dependency audit | `composer audit --locked` | always |
| Container image scan / Container image (Arm) | Builds the image, checks nginx and PHP extensions, fails on a fixable critical | container changes |
| Compose stack starts | Starts the stack as production and asks `/api/health/ready` | app or container changes |
| Dynamic scan | Unauthenticated scan of the running app and its OpenAPI; does not gate | nightly or by hand |

The sandbox job depends on ZATCA's servers, so it does not run on every push.
When you change the filing path, put `[sandbox]` in the commit message to run
the round trip before merging; a `GATEWAY` line in its output is a timeout at
ZATCA, not a refusal.

## Reading a CI failure

Failed tests are posted as annotations (`scripts/ci/annotate-junit.php`;
`run-conformance.sh` does the same for conformance suites), so the run's
summary page shows the test, the class and the first line of the failure
without opening the log. From the command line:

```bash
gh run view <run-id>
gh api repos/<owner>/<repo>/check-runs/<check-run-id>/annotations
```

A conformance job that ends in "Suite skipped" or "No tests ran" did not
check anything: fix the tool path or the filter, not the test.

# Codebase tour

Audience: an engineer new to the repository who needs to know where things
live. Why the parts are shaped this way is in [docs/architecture](../architecture/JURISDICTION-ROUTING.md);
this page is only the map.

## app/

| Path | What lives there |
|---|---|
| `app/Domains/` | All domain code, one folder per domain, each with its own `Models`, `Services`, `Http`, `DTOs`, `Console` ... as it needs them |
| `app/Http/` | The few cross-domain HTTP pieces: base `Controller`, a few shared middleware (`SetLocale` ...), `Listing/` (sort and filter toolkit for lists), `Pagination/`, `Responses/ApiResponse` (the one API envelope) |
| `app/Console/Commands/` | Platform-wide commands: `platform:openapi` (`GenerateOpenapi`, with `Openapi/RouteReader`, `RequestDescriber` and `ResponseDescriber`), `platform:schema`, `platform:static-errors`, index and partition maintenance |
| `app/Support/` | Small shared helpers: `Brand`, `CountryClock`, `SafeFetch`/`SafeUrl` (outbound requests), `Xml`, `QrCodeRenderer`, `EscPos`, `Errors/` |
| `app/Providers/` | `AppServiceProvider` (middleware aliases, boot guards) and `EventServiceProvider` |
| `app/Logging/` | `RedactSecrets`, which keeps secrets out of every log channel |

### Domains

| Domain | One line |
|---|---|
| `Audit` | The audit log and attribution (`Attribution/Channel`, `Origin`: who, how and which request) |
| `Auth` | Users, sign-in (session and JWT), two-factor, admin roles and invitations, platform ownership |
| `Billing` | The platform billing its own customers: payments, receivables, refunds, expenses, statement reconciliation, payment gateways |
| `Books` | An organization's own books: suppliers, purchase invoices, payments, vouchers, deemed supplies, the VAT return draft |
| `Branding` | Themes, logos and the theme stylesheet |
| `Compliance` | The compliance engine, the country modules and the networks (below) |
| `Invoice` | Invoices and notes, lines, customers, products, schedules, CSV imports, templates, PDFs and receipts; returns and exchanges (`InvoiceAdjustments`), delivery notes and summary invoices (`SummaryInvoices`), contracts billed in stages (`ProgressBilling`) |
| `Licensing` | Licences (plans and allowances), API keys, usage and rate limits |
| `Organization` | Organizations, groups, branches, tills (EGS units), memberships, invitations, the tenant scope and `RecordsActors` |
| `Pipeline` | `/api/v1/pipeline/*`: issue and file in one call (`submit`), in batches (`batch`), split, and status |
| `Platform` | The admin console, feature access (`Access/`), approvals (`Approvals/`), customization (`Customization/`), the public website and the developer page |
| `Referral` | Referral codes, referrals and rewards |
| `Support` | Support tickets, feature requests and the public feature board |
| `Webhook` | Customer webhook endpoints, events and signed delivery |

### Compliance

| Path | What lives there |
|---|---|
| `Compliance/Engine/Contracts/` | The ports every country implements (`Jurisdiction`, `Transmission`, `InvoiceRules`, `SellerRules`, `Presentation`, `TaxReports`, `SetupSteps`, `Dashboard` ...) |
| `Compliance/Engine/Services/` | `ComplianceEngine`, `JurisdictionRegistry`, `JurisdictionResolver`, `JurisdictionAvailability` |
| `Compliance/Countries/SaudiArabia/` | ZATCA (Fatoora): `Module`, `ModuleServiceProvider`, `Issuing/`, `Document/` (UBL), `Signing/` (XAdES, hash, TLV QR), `Filing/` (clearance, reporting, offline queue, ledger; `Filer` is the one send path), `Onboarding/`, `Rules/` (`InvoiceRules`, with `DraftDefaults`, `DraftChecks`, `TaxTreatmentChecks` and `BilledBuyer`), `Reports/`, `Presentation/`, `Console/` (`fatoora:*`) |
| `Compliance/Countries/UnitedArabEmirates/` | PINT AE: `Module`, `ModuleServiceProvider`, `Issuing/`, `Document/PintMapper`, `Rules/`, `Reports/`, `Presentation/` |
| `Compliance/Networks/Peppol/` | The shared Peppol network: `Sending/`, `Receiving/`, `Gateways/` (the service-provider gateway), `Simulator/`, `Console/` (`peppol:*`) |

Modules are registered in `bootstrap/providers.php`; their console folders are
listed in `bootstrap/app.php` (`withCommands`).

## routes/

| File | Surface |
|---|---|
| `routes/web.php` | Pages: public website, sign-in and sign-up, `/account`, `/admin` (console), `/portal` |
| `routes/finance.php` | Admin › Finance, required from inside the `/admin` group |
| `routes/api.php` | Loads the four API files below, split by who is calling |
| `routes/api/public.php` | No credential: the whole unauthenticated API |
| `routes/api/tenant.php` | `jwt.auth`: a signed-in person acting for one organization |
| `routes/api/partner.php` | `license`: an integration acting for its customer, under `/api/v1` |
| `routes/api/platform.php` | `platform.admin`: platform staff across every tenant |
| `routes/licensing.php` | Licence management API, loaded from `bootstrap/app.php` |
| `routes/console.php` | The scheduler: every scheduled task |

## resources/

| Path | What lives there |
|---|---|
| `resources/views/components/ui/` | The design system: `<x-ui.*>` components (button, field, table, section, modal, badge, date, amount ...) |
| `resources/views/layouts/` | Page layouts: `admin`, `portal`, `guest`, `public` |
| `resources/views/admin/`, `portal/`, `account/`, `auth/`, `home/` | Console, portal, account, sign-in and public website screens |
| `resources/views/countries/{saudi-arabia,united-arab-emirates}/` | A country module's own views |
| `resources/views/networks/peppol/` | The Peppol network's views |
| `resources/views/pdf/`, `print/`, `invoice-template/` | Invoice PDF, print and template rendering |
| `resources/views/emails/` | Mail |
| `resources/css/`, `resources/js/` | Tailwind stylesheet and the small Alpine-based scripts, built by Vite |
| `resources/developers/openapi.json` | The API reference the `/developers` page reads (generated) |

## lang/

`lang/ar.json` holds the Arabic for every `__()` string (English is the key).
`lang/ar/*.php` are the framework's own messages (validation, auth,
pagination, passwords).

## database/

| Path | What lives there |
|---|---|
| `database/migrations/` | Numbered migrations, `NNNN_what_it_does.php` |
| `database/factories/` | Model factories for tests |
| `database/seeders/` | `DevUsersSeeder` (local sign-in), `DatabaseSeeder`, a one-off backfill |
| `docs/schema.sql` | The schema as migrations build it, generated by `php artisan platform:schema` |

## tests/

| Path | What lives there |
|---|---|
| `tests/Unit/` | Unit tests |
| `tests/Feature/` | Feature tests, one folder per domain (`Invoice/`, `Compliance/`, `Security/` ...) |
| `tests/Feature/Architecture/` | Ratchets over the whole codebase ([TESTING-AND-GATES.md](TESTING-AND-GATES.md)) |
| `tests/Feature/Compliance/GoldenMaster/` | Byte-for-byte snapshots of Saudi issuing |
| `tests/Fixtures/` | Shared traits (`MakesInvoices`, `GoldenMaster`, `ZatcaSdk` ...) and data: `golden/` (snapshots and fixed test keys), `Certificates/`, `xsd/`, `zatca/`, `statements/`, `website/` |
| `tests/E2e/run.php` | End-to-end run over real HTTP against MySQL; not part of PHPUnit |
| `tests/Load/` | Load-test scripts, run by hand |

## Everything else

| Path | What lives there |
|---|---|
| `scripts/ci/` | `run-conformance.sh` (fails unless a conformance suite truly ran) and `annotate-junit.php` (failed tests as CI annotations) |
| `.github/workflows/` | `ci.yml` (every gate) and `build-and-push.yml` (release image on a version tag) |
| `docker/` | Image runtime: `entrypoint.sh`, `nginx/`, `supervisor/` (php-fpm, nginx, queue workers, scheduler), `monitoring/`, `.env.template` |
| `Dockerfile`, `docker-compose*.yml` | The image and the compose stacks (development override, production) |
| `cloudflare-worker/` | The phone-home licence server, a small script deployed outside the app |
| `docs/` | Documentation; `docs/openapi.yaml` and `docs/schema.sql` are generated and checked by tests |

There is no client SDK folder in this repository; the API is described by
the generated `docs/openapi.yaml`.

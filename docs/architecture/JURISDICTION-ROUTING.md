# Jurisdiction routing

Audience: engineers changing the compliance engine, a country module or a
shared screen that shows a country.

How an invoice reaches the right country's rules. Each country is a module
plugged into one engine, and shared code asks the engine rather than asking
"which country is this?". What each domain may depend on is in
[DOMAINS.md](DOMAINS.md); how a document moves once sent is in
[FILING-STATES.md](FILING-STATES.md).

## The engine

`app/Domains/Compliance/Engine/` names no country and imports no module
(`CountryCheckTest`, `ModuleBoundaryTest`).

| Part | Class | What it does |
|---|---|---|
| Module contract | `Contracts\Jurisdiction` | Identity (`code()`, `name()`, `timezone()`, `currency()`, `filingSystem()`), `enabled()`, `readiness()` (configuration only), `readinessProblems()` (the production probe), and one accessor per port |
| Registry | `Services\JurisdictionRegistry` | `register()`, `get()`, `orDefault()` (the default for a blank or unregistered code), `registered()`, `enabled()` |
| Resolver | `Services\JurisdictionResolver` | `forOrganization()` and `forInvoice()` (refuse an unregistered or switched-off country), `available()` (no throw, for the banner), `forRules()` (the organization's module, on or off, else the default) |
| Availability | `Services\JurisdictionAvailability` | Each country's switch: an admin's row in `settings` (`jurisdiction.<CC>.enabled`), else the module's configured default |
| Entry point | `Services\ComplianceEngine` | `issue()` and `resume()` resolve the country and delegate to its transmission; `jurisdictionFor()` returns the module |
| API gate | `Http\Middleware\JurisdictionAvailable` (`jurisdiction.available`) | On routes that start new work; `422 JURISDICTION_UNAVAILABLE` |
| DTOs | `IssueOptions` (`file`, `idempotencyKey`), `IssueOutcome` (`issued`, `attemptedFiling`, `errors`, `warnings`, `submission`), `Panel`, `InvoicePage` | One neutral answer for every country |

### The ports

Each is an interface in `Engine/Contracts/`, returned by the accessor of the
same name on `Jurisdiction`.

| Port | Answers |
|---|---|
| `issuingStep()` (`Invoice\Contracts\IssuingJurisdiction`) | The country's steps inside `IssuingLifecycle` (below) |
| `Transmission` | `issue()`, `resume()`, `readyToFile()`, `live()` (live authority or network rather than sandbox or simulator; `SandboxGuard` keeps a sandbox key off a live organization) |
| `IssueNotices` (`notices()`) | Portal wording after issuing; the authority's advisories and rejection reasons |
| `FeatureSupport` (`features()`) | Which features exist in the country and its own kill switches ([ACCESS.md](ACCESS.md)) |
| `SellerRules` | Seller details beyond the names: rules, sign-up and company forms, the VAT number it registers under, where details are saved, `complete()` |
| `InvoiceRules` | Draft defaults, rules, cross-field checks, `draft()` and `countryFields()`, rate and code lists, the portal form, buyer fields, `billedBuyer()` |
| `VatTreatments` | Tax categories and exemption reasons offered, from the official code lists (`InvoiceRules` is what is enforced) |
| `LanguageRules` | Whether Arabic is required, print languages, what an invoice still lacks |
| `RecurringSupply` | What a scheduled invoice adds for a periodic supply |
| `SplitBilling` | Patient and insurer shares, where the country bills that way |
| `SpecialSupplies` (`specialSupplies()`) | What the law allows beyond one sale on one invoice: a month's summary invoice and its deadline, a contract stage or retention release and when tax on held retention falls due, how a disbursement is taxed (or null), whether a voucher sale is a supply, deemed-supply thresholds and their kept invoice |
| `Presentation` | Tagline, navigation (`NAV_MAIN`, `NAV_ADMIN`, `NAV_CONSOLE`), `invoicePage()`, `assertShareable()`, `sealPdf()`, print templates, receipts, country-only customizations |
| `TaxReports` (`reports()`) | Reports page, two CSVs, totals, daily close |
| `VatReturn` | The draft return; whether input tax waits on payment ([BOOKS-AND-FINANCE.md](BOOKS-AND-FINANCE.md)) |
| `Dashboard` | Portal dashboard part and `/api/dashboard` overview, health, filings, activity |
| `SetupSteps` (`setup()`) | The country's checklist steps: company, onboarding, first invoice |
| `Sectors` | Business types the mandate leaves out; asked when a type is offered, never when issuing |
| `Devices` | A branch's device status and why issuing from it would be refused; null where none is registered |
| `Website` | What the public site says of the country |

### How the two countries answer

| | Saudi Arabia (`SA`, default) | United Arab Emirates (`AE`) |
|---|---|---|
| Switch default | `fatoora.enabled` (`ZATCA_ENABLED`), on | `peppol.countries.AE.enabled` (`PEPPOL_AE_ENABLED`), off |
| `readyToFile()` | `zatca_onboarded` and a production certificate for the organization's own ZATCA environment | Always: the provider carries every document |
| `live()` | The organization's environment is `production` (`FatooraEnvironment`) | `peppol.gateway` is not `simulator` |
| `assertShareable()` | Issued, with a QR, not rejected, and a standard invoice cleared | Issued and not rejected |
| `sealPdf()` | The device's PAdES stamp | As it is: no seal is asked for |

<!-- app/Domains/Compliance/Countries/*/Module.php; */Presentation/Presentation.php -->

## Choosing and switching a country

`forOrganization()` reads the organization's `country`: blank means the
default (Saudi) module; an unregistered or switched-off country is refused
with `JurisdictionUnavailableException`. The pipeline answers that refusal
with `422 JURISDICTION_UNAVAILABLE` before validating anything.

A platform admin switches a country on the console's **Countries** page
(`/admin/countries`, `AdminCountryController`, `platform.configure`; held by
the `countries.switch` approval policy if on). When a country is off: new
issuing is refused (banner, issue button, API 422), sign-up does not offer
it, drafts and every read stay open, and work in flight - the offline queue,
submission recovery, Peppol sending and callbacks - carries on.

Each module registers itself from its `ModuleServiceProvider` (listed in
`bootstrap/providers.php`) with `afterResolving(JurisdictionRegistry::class,
...)`; the Saudi one passes `default: true`. Modules are built from the
container on each request for them.

For each `ComplianceEngine` call, log lines carry `jurisdiction`, `step`,
`org_id` and `invoice_id` (`Context::scope()`), and jobs queued inside carry
them too.

## Who calls the engine

| Caller | Call |
|---|---|
| `Pipeline\Services\PipelineService` | `jurisdictionFor()` and its `InvoiceRules` to draft; `issue()` (`file` = `auto_submit`); `resume()` for a retry that finds the invoice issued |
| `Invoice\Http\Controllers\PortalInvoiceFilingController` | `issue()`, `file` from `readyToFile()` |
| `Billing\Services\Billing` | `issue()` with `file: true` for the platform's own invoice; buyer from `billedBuyer()` |

The country APIs keep routes of their own: `/api/compliance/sa/*` reaches the
Saudi module, `/api/compliance/ae/*` sits behind `uae.invoicing` (403 for an
organization whose country is not `AE`); routes that start work also carry
`jurisdiction.available`. An organization has one country.

## The seam: one lifecycle, one step per country

Issuing is written once, in `Invoice\Services\IssuingLifecycle::issue($invoice,
$organization, $jurisdiction, $reissue)`. It owns the draft guard, the
transaction holding the issuing lock the step names (`lockScope()`: a Saudi
device, a UAE organization), the number from `InvoiceNumbering`, the moment
of issue and status `issued`. It calls the step at fixed points:

| Step | Saudi Arabia | United Arab Emirates |
|---|---|---|
| `refuseIssued()` | `FatooraException` | `IssuanceRefusedException` |
| `prepare()` (before the lock; throwing writes nothing) | Kill switches, clearance, ZATCA's rules, credentials; revocation and expiry checked before the counter | Kill switch, clearance, feature override; once past its e-invoicing start date, an appointed provider |
| `refuseIssuedMeanwhile()` / `finished()` | Refuse, or the finished result on a reissue | The stored document |
| `beforeNumbering()` | Allocates the device's counter (ICV) | Nothing |
| `seal()` | Hash, XAdES signature, QR | Mandatory fields, scenarios, totals |
| `afterIssued()` | Records the chain entry | Stores the Peppol document |
| `afterCommit()` | Nothing | `invoice.issued`, then the queued send |

Filing is the transmission's, not the seam's: a ZATCA clearance or report
runs after issuing in `InvoiceIssuer`; a Peppol document is settled later by
callbacks. Every country also shares `InvoiceDrafter`, `InvoiceNumbering`
(on the organization's clock, `App\Support\CountryClock`),
`Organization\Services\FilingClearance` and `IssuanceNotifier`; shared
refusals use the neutral `App\Support\Errors\ComplianceException`.

## Where a country lives

```
app/Domains/Compliance/
  Engine/                 the core; names no country
  Countries/SaudiArabia/  ZATCA (Fatoora)
  Countries/UnitedArabEmirates/  PINT AE
  Networks/Peppol/        shared Peppol transport, for any Peppol country
```

Every module has `Module.php`, `ModuleServiceProvider.php`, `Issuing/`,
`Document/`, `Rules/`, `Reports/`, `Presentation/` and Laravel's own
folders. Saudi Arabia adds `Filing/`, `Signing/`, `Onboarding/` and `Pilot/`;
the UAE has no `Filing/` because the network sends. A module has no
`Services/`: these folders are its services, and the dependency tests scan
them as such. Views live in `resources/views/countries/<kebab-name>/`, named
`countries.<country>.<view>`, and are held to the module's lines. The network
keeps `Gateways/`, `Sending/` (`PeppolSender`, `DocumentStatus`),
`Receiving/`, `Simulator/` and its views in `resources/views/networks/peppol/`.

Tests follow the module: `tests/Feature/Compliance/<Country>/`,
`tests/Feature/Compliance/Networks/Peppol/`, engine tests in
`tests/Feature/Compliance/Engine/`, and the two-country controls in
`tests/Feature/Compliance/JurisdictionIsolationTest.php`.

## What is still to move

`CountryCheckTest`'s list of country comparisons in shared code is empty.
Shared files that still name a module class are listed in
`ModuleBoundaryTest::SHARED` and can only shrink: the console's ZATCA panels,
branch credentials, model links, error rendering and module registration.
`CountryClock` keeps its own time-zone map rather than reading
`timezone()`, and the country APIs are not yet routed through the engine.
<!-- app/Support/CountryClock.php TIME_ZONES; tests/Feature/Architecture/ModuleBoundaryTest.php SHARED -->

`ComplianceProfile.jurisdiction` is an ISO 3166-1 alpha-2 code, unique per
organization. Who must file is each country's law: see
[the Saudi README](../compliance/sa/README.md) and
[the UAE README](../compliance/ae/README.md). Adding a country:
[ADDING-A-JURISDICTION.md](ADDING-A-JURISDICTION.md).

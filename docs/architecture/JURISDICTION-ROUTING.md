# Jurisdiction routing

This page describes how an invoice reaches the right country's rules. Each
country is a module plugged into one engine, and shared code asks the engine
rather than asking "which country is this?".

The engine is being built in phases. This page describes what exists now
(phase 1: the engine core and the issue and transmit routing; phase 2: the
rules ports; phase 3: the presentation, reports, dashboard and setup ports)
and marks what a later phase adds.

## The engine

The engine lives in `app/Domains/Compliance/Engine/`. It names no country and
imports nothing from a country module. `CountryCheckTest` asserts both.

| Part | Class | What it does |
|---|---|---|
| Module contract | `Engine\Contracts\Jurisdiction` | A country's identity (`code()`, `name()`, `timezone()`, `currency()`, `filingSystem()`), whether this deployment offers it (`enabled()`), what an operator checks before switching it on (`readiness()`), and its ports |
| Availability | `Engine\Services\JurisdictionAvailability` | Every country's on/off switch: a platform admin's, else the module's configured default |
| Issuing step | `Invoice\Contracts\IssuingJurisdiction` (returned by `issuingStep()`) | The country's steps inside `IssuingLifecycle`, described below |
| Transmission port | `Engine\Contracts\Transmission` | `issue()` issues a draft and, when asked, files or sends it. `resume()` handles an invoice that is already issued. `readyToFile()` says whether the organization can file now |
| Notices port | `Engine\Contracts\IssueNotices` | What the portal tells a person after issuing: `filed()` and `notFiled()` |
| Features port | `Engine\Contracts\FeatureSupport` (returned by `features()`) | Which of the platform's features exist in the country (`supportsFeature()`) and the kill switches its own steps obey (`killSwitchesFor()`), for [Access](ACCESS.md) |
| Seller rules port | `Engine\Contracts\SellerRules` (returned by `sellerRules()`) | The seller's details, described in [The rules ports](#the-rules-ports) |
| Invoice rules port | `Engine\Contracts\InvoiceRules` (returned by `invoiceRules()`) | Drafting an invoice, described in [The rules ports](#the-rules-ports) |
| Presentation port | `Engine\Contracts\Presentation` (returned by `presentation()`) | The portal's tagline and the country's sidebar links, an invoice's page, and when and how an invoice is handed to the buyer, described in [The presentation ports](#the-presentation-ports) |
| Tax reports port | `Engine\Contracts\TaxReports` (returned by `reports()`) | The portal's Reports page and its two CSVs |
| Dashboard port | `Engine\Contracts\Dashboard` (returned by `dashboard()`) | The portal dashboard's country part, and `/api/dashboard`'s filings, health, usage and activity |
| Setup steps port | `Engine\Contracts\SetupSteps` (returned by `setup()`) | The country's steps in the setup checklist |
| Website port | `Engine\Contracts\Website` (returned by `website()`) | What the public website shows of the country: how its invoices are filed (`offering()`, a plain sentence that claims no certification), the standards its documents are built to (`standards()`), and the QR code its invoices carry, drawn for the home page's sample invoice (`sampleQr()`, from `Engine\DTOs\SampleInvoice` to `Engine\DTOs\SampleQr`; Saudi Arabia's Phase 1 TLV, none for the United Arab Emirates). Asked by `Platform\Services\PublicSite` for every registered country |
| Views | `Engine\DTOs\Panel`, `Engine\DTOs\InvoicePage` | A country's Blade view and its data, for a shared screen to include; what an invoice's page shows for its country |
| Options | `Engine\DTOs\IssueOptions` | `file` (no default: filing is irreversible) and `idempotencyKey` |
| Outcome | `Engine\DTOs\IssueOutcome` | One neutral answer for every country: `issued`, `attemptedFiling`, `errors`, `warnings`, and the raw `submission` |
| Registry | `Engine\Services\JurisdictionRegistry` | The modules registered with this deployment, and which of them are enabled. `orDefault()` is a country code's module, or the default for a blank or unregistered code |
| Resolver | `Engine\Services\JurisdictionResolver` | `forOrganization()` and `forInvoice()`. `available()` answers the same question without throwing, for the portal's banner. `forRules()` is the module whose rules a form or request follows: the organization's, switched on or not, else the default |
| API gate | `Engine\Http\Middleware\JurisdictionAvailable` (`jurisdiction.available`) | On each country's routes that start new work. Refuses a switched-off country with `422 JURISDICTION_UNAVAILABLE` |
| Entry point | `Engine\Services\ComplianceEngine` | `issue()` and `resume()`, which resolve the country and delegate to its transmission. `jurisdictionFor()` gives a caller the module itself |
| Refusal | `Engine\Exceptions\JurisdictionUnavailableException` | No module for the country, or a module this deployment has switched off |

### How a country is chosen

`JurisdictionResolver::forOrganization()` reads the organization's `country`:

- A blank country means the default module. This covers rows from before
  the column was set, which were Saudi. The Saudi module registers itself
  as the default.
- A country with no registered module is refused with
  `JurisdictionUnavailableException`.
- A registered module whose `enabled()` is false is refused the same way.
  See [Switching a country on and off](#switching-a-country-on-and-off).

The pipeline API answers a refusal with `422 JURISDICTION_UNAVAILABLE`
before any field is validated and before anything is drafted. The portal's
issue button answers with a message, and the draft stays a draft.

### Switching a country on and off

Every country has the same switch, and every module reads it the same way:
its `enabled()` asks `JurisdictionAvailability` for the country's code and
passes its own configured default.

| Country | Default | Read from |
|---|---|---|
| Saudi Arabia | on | `ZATCA_ENABLED` (`fatoora.enabled`) |
| United Arab Emirates | off | `PEPPOL_AE_ENABLED` (`peppol.countries.AE.enabled`) |

A platform admin overrides the default on the admin console's **Countries**
page (`/admin/countries`, `AdminCountryController`, permission
`platform.configure`). The page lists every registered module with its
filing system, whether it is on and whether an admin switch or the default
decided that, how many organizations are registered in it, and its
`readiness()`. Switching a country off asks for confirmation, says how many
organizations will stop issuing, and warns when the billing organization is
in that country, since platform billing stops too.

The switch is a row in `settings` (key `jurisdiction.<CC>.enabled`, with
`updated_by` and `updated_at`), cached as one entry and forgotten on write.
`AuditAdminAction` records each switch with the value before and after.

Nothing reads the environment variables directly any more. Everything that
needs to know whether a country is on asks the engine:

| Place | How it asks |
|---|---|
| Issuing (pipeline, portal, billing) | `JurisdictionResolver::forOrganization()` |
| Each country's API write routes | `jurisdiction.available` |
| Sign-up and the company country list | `CompanyProfileRules::countries()`, which is `JurisdictionRegistry::enabled()` |
| Portal banner | `JurisdictionResolver::available()` |

The production boot guard no longer looks at the UAE's switch. A connected
service provider needs its credentials whether or not the UAE is on, because
an admin can switch it on after boot.

When a country is off, the behaviour is the same for every country:

- New issuing is refused. The portal says so on the issue button and in a
  banner on every page; the API answers `422 JURISDICTION_UNAVAILABLE`.
- Sign-up and the company page do not offer the country.
- Portal drafts can still be saved, and every screen and read route stays
  open. The pipeline still refuses before drafting.
- Work already in flight is not stranded. The background paths do not go
  through the engine's check: the ZATCA offline queue, submission recovery
  and the submission job keep filing, and Peppol sending, resending, status
  polling and callbacks keep running.

### How modules register

Each module registers itself from its own service provider. The registry is
a singleton holding one factory per country code. A module is built from the
container each time it is asked for, so a test that swaps a collaborator
after boot still reaches the module.

| Country | Module | Registered by |
|---|---|---|
| Saudi Arabia (`SA`, default) | `Compliance\Countries\SaudiArabia\Module` | `Compliance\Countries\SaudiArabia\ModuleServiceProvider` |
| United Arab Emirates (`AE`) | `Compliance\Countries\UnitedArabEmirates\Module` | `Compliance\Countries\UnitedArabEmirates\ModuleServiceProvider` |

Each module adapts the issuer it already had:

- **Saudi Arabia.** `issue()` calls `Issuing\InvoiceIssuer` (sign, then
  file through `Filing\OfflineFallback`). `resume()` files an issued invoice that
  was not filed, unless it waits in the offline queue. `readyToFile()` is
  `zatca_onboarded`.
- **United Arab Emirates.** `issue()` and `resume()` call `Issuing\InvoiceIssuer`,
  which queues the Peppol document once when `file` is true. The document
  and any late-issue warnings are mapped into `IssueOutcome`. `readyToFile()`
  is always true, because the service provider carries every document.

### Log context

For the length of each `ComplianceEngine` call, every log line carries
`jurisdiction`, `step` (`issue` or `resume`), `org_id` and `invoice_id`. The
values are set with `Context::scope()`, which restores the previous context
when the call returns. A job queued during the call carries them too.

## The rules ports

Where the rules differ by country, shared code asks the organization's
module, or the country chosen at sign-up, and never names a country.

### Seller rules

`SellerRules` is what the seller's details have to be and where they are
kept. `Organization\Rules\CompanyProfileRules` is the shared part: the
company's names, the country choice (`countries()`, `country()`), and the
one-VAT-number-one-taxpayer check (`unclaimed()`), which each country puts on
the number it registers under. `CompanyProfileRules::rules($country, ...)`
and `messages($country)` add the country's own, and
`CompanyProfileRules::jurisdiction($country)` is the module for a country
code (the default for a blank or unregistered one).

| Method | What it answers | Saudi Arabia | United Arab Emirates |
|---|---|---|---|
| `rules(bool $complete, ?Organization $existing): array` | The details beyond the names. `$complete` is the company page and sign-up; otherwise each field is checked when sent, as the API allows | VAT number (BR-KSA-40, fixed once onboarded), CR number, the national address (BR-KSA-37, BR-KSA-66) | Address with its emirate, TRN or TIN (`UaeTin`), Peppol endpoint, legal registration, payment account |
| `messages(): array` | The messages for those rules | | |
| `signupFields(): array` | The keys sign-up's section sends | VAT number, CR number, address | Everything but the endpoint and the payment account |
| `signupView(): string` | Sign-up's section, a Blade view | `auth.partials.saudi-signup` | `auth.partials.uae-signup` |
| `companyView(): string`, `companyData(Organization): array` | The company page's form, and what it shows beside the organization's columns | `portal.partials.company-saudi`, `onboarded` | `portal.partials.company-uae`, the stored `uae` settings |
| `vatNumber(array $details): ?string` | The VAT number the organization registers under | `vat_number` | The TRN; none for a seller with only a TIN |
| `save(Organization, array $details): void` | Saves the company page's or the API's details, `prices_include_vat` included | The organization's columns | With any UAE setting, `UaeSellerDetails` (the organization and its UAE compliance profile); otherwise the columns only |
| `registered(Organization, array $details): void` | After sign-up or `POST /api/organizations` registered the organization, what else the country keeps | Nothing | The settings, on the UAE profile |
| `complete(Organization): bool` | Whether the documents have every seller detail they need, for the setup checklist | `hasCompleteZatcaProfile()` | `UaeSellerDetails::complete()` |

Asked by `SignupRequest`, `SelfSignup`, `SignupController` (one section per
country offered, the default first), `PortalCompanyController`,
`OrganizationController::store()` and `update()`, the UAE's
`SaveUaeProfileRequest`, and `SetupChecklist`.

### Invoice rules

`InvoiceRules` is what an invoice draft has to be, and the columns only the
country's invoices carry. `Invoice\Http\Requests\CountryInvoiceRequest` asks
one country's rules for its `rules()`, `messages()`, `withValidator()` and
`prepareForValidation()`, and maps the validated request with `draft()` and
`countryFields()`. Its subclasses say only which country:
`CreateInvoiceRequest` (the API), `PipelineSubmitRequest` and
`CreatePortalInvoiceRequest` take the organization's
(`JurisdictionResolver::forRules()`, or `forOrganization()` for the
pipeline); the UAE's `CreateUaeInvoiceRequest` takes its own module's.

| Method | What it answers | Saudi Arabia | United Arab Emirates |
|---|---|---|---|
| `defaults(Request): array` | What is filled in before validation | Document type, `SAR`, payment means `10`; each line's unit, category and rate from its exemption code | Document type and category, `AED`, no transaction flags, buyer country `AE` |
| `rules(Request): array`, `messages(): array` | The request's rules and messages | ZATCA's, as `CreateInvoiceRequest` had them | PINT AE's, as `CreateUaeInvoiceRequest` had them |
| `after(Validator, Request): void` | The checks across fields | An exemption code for Z, E and O lines; no discount past the lines (BR-KSA-F-04) | Once every field is well-formed: each line's category in its document and scenario, the 5% rate, no discount past the lines |
| `draft(array $validated): array` | The draft as `InvoiceDrafter` takes it | The validated request as it is | Net prices, the item name as the description, only standard lines taxed |
| `countryFields(array $validated): array` | The columns only this country's invoices carry | None | `due_date` and `jurisdiction_data` |
| `billedBuyer(Organization $buyer): array` | How the seller names an organization as its buyer on an invoice drafted straight from its details, past the request rules, and whether those details are enough. Throws `DomainException` naming what is missing | Its VAT number; a registration outside the Kingdom as another ID (`OTH`, BR-KSA-44); else its CR number (`CRN`, BR-KSA-81); then the full national address of a buyer in the Kingdom (BR-KSA-63) | Its TRN, else refused |
| `standardRate()`, `taxCategories()`, `exemptionCodes()`, `documentTypes()`, `invoiceTypes()` | The country's defaults and code lists | `15`; S, Z, E, O; `VATEX-SA-*`; every kind but proforma; standard and simplified | `5`; PINT AE's; its exemption reasons; invoice and credit note; standard |
| `portalView()`, `portalRoute()` | The portal's new-invoice form and the route it posts to | `portal.invoices.create`, `portal.invoices.store` | `portal.invoices.create-uae`, `portal.invoices.uae.store` |
| `buyerRules(Request)`, `buyerMessages()`, `buyerView()` | A customer's buyer details beyond the shared ones, replacing a shared rule in place | A Saudi buyer's postal code is 5 digits (BR-KSA-67); no extra fields | Peppol endpoint and emirate, in `portal.customers.partials.uae-buyer` |

Asked by the API's `InvoiceController`, the pipeline (`PipelineSubmitRequest`
validates, `PipelineService` drafts with `draft()` and `countryFields()`),
the portal's `PortalInvoiceDraftController` (the form) and
`CreatePortalInvoiceRequest` (which sends an organization whose country's
form posts elsewhere back to that form before checking anything),
`PortalCustomerController`, and `Billing\Services\Billing` (`billedBuyer()`,
by the billing organization's country, for the platform's invoice for a
plan).

The pipeline resolves the organization's country first, in
`PipelineSubmitRequest`: a country this deployment does not issue in is
refused with `422 JURISDICTION_UNAVAILABLE` before any field is checked.
ERP aliases are kept where the country's rules do not name the field
themselves: `invoice_type` becomes `type`, and `due_date` becomes the supply
date only for a country whose invoices have no due date of their own (Saudi
Arabia); a UAE invoice keeps its due date.

## The presentation ports

How the portal and the dashboards show a country. Shared screens ask the
organization's module through `JurisdictionResolver::forRules()`, so a
switched-off country's screens and copies stay open; only new issuing stops.

### Presentation

| Method | What it answers | Saudi Arabia | United Arab Emirates |
|---|---|---|---|
| `tagline(): string` | What the portal calls itself, under the logo | ZATCA Portal | UAE E-invoicing Portal |
| `navigation(string $section): ?string` | The Blade view of the links the country adds to the sidebar: `NAV_MAIN` (after Invoices, for everyone) or `NAV_ADMIN` (among the administrator's links) | ZATCA onboarding, for administrators | Received invoices, for everyone |
| `invoicePage(Invoice): InvoicePage` | What an invoice's page shows for the country: the kind of document (`heading`), the Issue button's question (`issueConfirm`), what can be done once issued (`actions`), where it stands (`panel`), the lines under the buyer (`buyerDetails`), the rate tax was reported at (`exchangeNote`), and whether the PDF can be emailed (`shareable`) | Tax or simplified tax invoice, in both languages; print, credit and debit note, and PDF once shareable; counter, hash and QR; VAT number; the SAR rate; shareable once issued and, for a standard invoice, cleared | One of the six PINT AE document kinds; the e-invoice's XML; its Peppol status, message id and errors; TRN and Peppol endpoint; the AED rate; never shareable |
| `assertShareable(Invoice): void` | Whether the invoice may be given to the buyer now - downloaded, printed or emailed - throwing `DomainException` with why not | Issued, with a QR, not rejected, and a standard invoice cleared | Never: the buyer receives the e-invoice itself. The message is the one it was given before |
| `sealPdf(string $pdf, Invoice, Organization): string` | The rendered PDF as the country hands it over | The device's PAdES stamp (`Signing\PdfStamp`, `Signing\CadesSignature`), with the credentials the XML was signed with; as it is in Phase 1 | As it is (never reached: `assertShareable` refuses first) |

`IssueNotices` (`notices()`) is part of the same concern, kept a port of its
own because the issue action asks for it alone.

Asked by the portal layout (tagline and links), `PortalInvoiceController`
(the invoice page), and `Invoice\Services\InvoicePdf`, which stays the
shared renderer: `render()` asks `assertShareable()` first and `sealPdf()`
last, and the print view and the email action go through the same
`InvoicePdf::assertShareable($invoice, $organization)`. The PDF's bytes are
unchanged.

### Tax reports

| Method | Saudi Arabia | United Arab Emirates |
|---|---|---|
| `page(string $organizationId, $from, $to): Panel` | `countries.saudi-arabia.reports`, the VAT return's boxes in riyals (`VatSummary`) | `countries.united-arab-emirates.reports`, sales by UAE tax category in dirhams (`UaeVatSummary`) |
| `invoicesCsv(...)` | `InvoiceCsv::invoices()` | `InvoiceCsv::uaeInvoices()` |
| `summaryCsv(...)` | `InvoiceCsv::summary()` | `InvoiceCsv::uaeSummary()` |

Asked by `PortalReportController`, which keeps the period, its presets and
the file names.

### Dashboard

| Method | What it answers | Saudi Arabia | United Arab Emirates |
|---|---|---|---|
| `heading(): string` | The portal dashboard's subheading | Your ZATCA compliance overview | Your UAE e-invoicing overview |
| `portal(Organization): Panel` | The portal dashboard below the setup steps, and its figures, handed to the view as they are | Certificate alert, invoices and submission counts, activity by person, recent submissions | Invoices and e-invoice counts, recent e-invoices |
| `overview(string $organizationId): array` | `GET /api/dashboard` between `invoices` and `generated_at` | `submissions`, `compliance`, `certificates` | `e_invoices` |
| `health(string $organizationId): array` | `GET /api/dashboard/health`: `checks` (before `checked_at`) and `status` | Circuit breaker, offline queue, certificate; critical, warning or healthy | E-invoice counts; a warning while any was refused or given up on |
| `filings(string $organizationId): array` | `GET /api/dashboard/submissions` | Submission summary | E-invoice counts |
| `dailyFilings($since): array` | The usage chart's `submissions` per day | Submissions per day | None |
| `activity(int $limit): Collection` | `GET /api/dashboard/activity` | The latest submission events | None |

Asked by `CustomerPortalController::dashboard()` and
`Platform\Services\TenantDashboard` (for `DashboardController`), which keep
the invoice counts every country shares. The responses are unchanged:
`TenantDashboardTest` and `UaeTenantDashboardTest` pin their keys.

### Setup steps

`SetupChecklist` keeps the steps every country shares - approval, numbering
and the catalogue - and asks the country for the rest, in this order:

| Method | Saudi Arabia | United Arab Emirates |
|---|---|---|
| `company(Organization): array` | `company`: VAT number and national address | `uae_company`: TRN or TIN, address and emirate, legal registration, Peppol endpoint |
| `onboarding(Organization): array` | `zatca`: connecting to ZATCA | None |
| `firstInvoice(Organization): array` | `invoice`: done once an invoice has its hash | `uae_invoice`: done once any invoice is past draft |

Each step carries its `key`, `done`, `required` and `route`, the dashboard's
`title` and `detail`, and `member`: whether a member who is not an
administrator may start it.

## Where a country lives

```
app/Domains/Compliance/
  Engine/                   the core; names no country
  Countries/
    SaudiArabia/            ZATCA (Fatoora)
    UnitedArabEmirates/     PINT AE
  Networks/
    Peppol/                 shared Peppol transport, for any Peppol country
```

A country that files with its authority's own API is a module under
`Countries/`. A Peppol country is a module under `Countries/` plus the shared
`Networks/Peppol/`: the module builds and checks the country's document, and
the network sends and receives it. `ModuleBoundaryTest` holds the lines: the
engine names neither layer, a country module never names another country
(its classes, or the country itself in code, comments or messages), and the
network names no country.

Every country module has the same top level, and the Saudi module is the
template. The namespace says which country, so the names at the root and the
issuing step are the same in every module.

| Entry | Holds | Saudi Arabia | United Arab Emirates |
|---|---|---|---|
| `Module.php` | `Module`: the engine's `Jurisdiction`, with its `Transmission`, `IssueNotices` and `FeatureSupport` ports; the rules ports are classes in `Rules/`, the presentation and setup ports in `Presentation/`, the tax reports and dashboard in `Reports/` | Identity, switch, and issue and resume through `InvoiceIssuer` | The same, through its `InvoiceIssuer` |
| `ModuleServiceProvider.php` | Registers `Module` with `JurisdictionRegistry`. Listed in `bootstrap/providers.php` | `SA`, the default | `AE` |
| `Issuing/` | `IssuingStep`, the country's `IssuingJurisdiction`; the issuer that calls `IssuingLifecycle`; what the step records at issue | `IssuingStep`, `InvoiceIssuer`, `ChainRecorder` | `IssuingStep`, `InvoiceIssuer` |
| `Document/` | The document the authority or network receives, built from an invoice | `DocumentBuilder`, `XmlBuilder`, the four `Ubl*Builder` parts, `Ubl` | `PintMapper`, `PintUblBuilder`, `PeppolPreparer` |
| `Filing/` | Transmission: the client, sending, tracking, retries, offline handling, the verdict | `FatooraClient`, `Submitter`, `SubmissionTracker`, `SubmissionLedger`, `SubmissionGuard`, `ClearanceState`, `InvoiceVerdict`, `OfflineFallback`, `OfflineQueue`, `Requeuer`, `CircuitBreaker`, `Connectivity`, `DuplicateDetector`, `TimestampValidator` | None: the network sends |
| `Rules/` | The country's validation, tax rules and constants, and the rules ports | `SellerRules`, `InvoiceRules`, `FatooraConfig`, `InvoiceValidator`, `VatPeriodTracker` | `SellerRules`, `InvoiceRules`, `PintAe`, `MandatoryFields`, `IssueDeadline`, `UaeTin` |
| `Reports/` | What dashboards and reports read, and the `TaxReports` and `Dashboard` ports | `TaxReports`, `Dashboard`, `ChainReport`, `DeadlineReport`, `SubmissionReport`, `QueueBacklog` | `TaxReports`, `Dashboard`, `UaeDocuments`, `UaeVatSummary` |
| `Presentation/` | How the portal and the public website show the country: the `Presentation`, `SetupSteps` and `Website` ports | `Presentation`, `SetupSteps`, `Website` | `Presentation`, `SetupSteps`, `Website` |
| `Signing/` | Only where the authority requires a cryptographic seal: keys, certificates, signature, hash, QR, the PDF's stamp | `CredentialStore`, `SigningCredentials`, `CertificateService`, `CertificateChain`, `CertificatePem`, `EcdsaSigner`, `XadesSigner`, `XadesVerifier`, `XadesDigest`, `SignedPropertiesBuilder`, `InvoiceHasher`, `QrCodeGenerator`, `QrCodeInjector`, `TlvEncoder`, `PdfStamp`, `CadesSignature` | None |
| `Onboarding/` | Only where the authority registers each taxpayer or device | `CsidOnboarding`, `OrganizationOnboarding`, `ComplianceSampleSet`, `CsrBuilder`, `Der` | None |
| `Support/` | Helpers the module's services share | `FatooraTime`, `TextNormalizer` | `UaeDrafts`, `UaePortal`, `UaeSellerDetails` |
| `Http/`, `Console/`, `Jobs/`, `Events/`, `Listeners/`, `Mail/`, `Models/`, `Enums/`, `DTOs/`, `Contracts/`, `Exceptions/` | Laravel's kinds, as in every domain | `Http/` includes the portal's ZATCA screens: `PortalZatcaController` (onboarding) and `PortalSubmissionController` (submissions, each person's activity, the certificate) | `Http/` (the `/api/compliance/ae` and portal controllers, `UaeInvoicing`, the requests), `Enums/`, `DTOs/` (the PINT document), `Contracts/` (`JurisdictionProfile`) |
| `resources/views/countries/<country>/` | The Blade views the ports name, in the module's folder name in kebab case. A port names them `countries.<country>.<view>`, and a shared screen includes them by that name | `saudi-arabia/`: `navigation`, `dashboard`, `reports`, `invoice/heading`, `invoice/actions`, `invoice/panel` | `united-arab-emirates/`: the same six |

The network has the same kinds where it needs them, and capability folders
of its own:

| Entry | Holds | Peppol |
|---|---|---|
| `NetworkServiceProvider.php` | The network's bindings. Listed in `bootstrap/providers.php` | `AspGateway`, from `config/peppol.php` |
| `Contracts/`, `Gateways/` | The service provider, as the platform needs it | `AspGateway`; `HttpAspGateway`, `NullAspGateway`, `FakeAspGateway`, `WebhookSignature`, `WebhookPayload`, `AspStatus`, `InboundMessage` |
| `Sending/` | Handing documents over, once, and where each one is | `PeppolSender`, `DocumentStatus` |
| `Receiving/` | Documents other suppliers send | `InboundDocuments`, `ReceivedReader` |
| `Http/`, `Console/`, `Jobs/`, `Models/`, `DTOs/`, `Exceptions/` | Laravel's kinds | The status and inbound callbacks, `peppol:poll-status`, `peppol:resend`, `SendPeppolDocument`, `PeppolDocument` |

- A module leaves out a folder it does not need. It has no `Services/`: the
  folders from `Issuing/` to `Support/`, and the network's `Gateways/`,
  `Sending/` and `Receiving/`, are its services, and `WiredDependencyTest`,
  `ServiceLocatorTest` and `CommandConstructionTest` scan them as they scan
  `Services/`.
- A country's views are part of its module. `ModuleBoundaryTest` holds them
  to the module's lines (no other country's classes or words), does not
  count them as shared code, and fails for a folder of views with no module.
  The views the rules ports name (`signupView()`, `companyView()`,
  `portalView()`, `buyerView()`) are still under `resources/views/auth` and
  `resources/views/portal`, named for the country; they move to the
  country's folder when next touched.
- Inside the module, other classes keep the authority's vocabulary
  (`FatooraClient`, `FatooraException`), as [NAMING.md](../NAMING.md) says.
- A file that names two modules' `Module` or `ModuleServiceProvider` imports
  them with an alias.
- What every country uses lives outside the modules:
  `App\Support\Errors\ErrorCode` (the API's error taxonomy) and
  `App\Support\Errors\ComplianceException` (what the shared clearance,
  kill switch and pipeline refuse with, and the base of each country's own
  compliance exception),
  `Platform\Services\KillSwitch` (the issuing and filing switches, with the
  same switch names as before) and `Invoice\Support\AllowanceApportionment`
  (how a document discount is shared across tax categories).
- Tests follow the module: `tests/Feature/Compliance/<Country>/`,
  `tests/Feature/Compliance/Networks/<Network>/` and
  `tests/Unit/Domains/Compliance/Countries/<Country>/`. Engine tests stay in
  `tests/Feature/Compliance/Engine/`; portal screens stay in
  `tests/Feature/Portal/`. A country's tests use only its own
  organizations. A test that brings in the other country as a control is in
  `tests/Feature/Compliance/JurisdictionIsolationTest.php`.

## Who calls the engine

| Caller | Call |
|---|---|
| `Pipeline\Services\PipelineService` | `jurisdictionFor()` first, then the module's `InvoiceRules` to draft. `issue()` for a draft. `resume()` for a retry that finds the invoice issued. `file` is the request's `auto_submit` |
| `Invoice\Http\Controllers\PortalInvoiceFilingController` | `issue()`, with `file` from the module's `readyToFile()` and the flash text from its notices |
| `Billing\Services\Billing` | `issue()` with `file: true`, for the billing organization's own invoice; the buyer's details from the billing organization's `invoiceRules()->billedBuyer()` |

The country APIs keep their own routes. `/api/compliance/sa/*` reaches
the Saudi module directly. `/api/compliance/ae/*` sits behind the `uae.invoicing`
middleware, which answers `403` for an organization whose `country` is not
`AE`. In both, the routes that start new work also carry
`jurisdiction.available`.

An organization has one country. Issuing for a second jurisdiction from the
same organization is not supported.

## The seam: one lifecycle, one step per country

Issuing is written once, in `App\Domains\Invoice\Services\IssuingLifecycle`.
Each country supplies its part by implementing
`App\Domains\Invoice\Contracts\IssuingJurisdiction`, which its module returns
from `issuingStep()`.

| Jurisdiction | Implementation | Called from |
|---|---|---|
| Saudi Arabia | `Compliance\Countries\SaudiArabia\Issuing\IssuingStep` | `Submitter::generate()` |
| United Arab Emirates | `Compliance\Countries\UnitedArabEmirates\Issuing\IssuingStep` | `Issuing\InvoiceIssuer::issue()` |

`IssuingLifecycle::issue($invoice, $organization, $jurisdiction, $reissue)`
owns what every country shares:

- the guard against issuing an invoice that is no longer a draft;
- the transaction, holding the issuing lock of the chain the jurisdiction
  names (`lockScope()`: a Saudi device, a UAE organization);
- the number, from `InvoiceNumbering`, when the draft has none;
- the moment of issue, set once;
- status `issued`, saved with the columns the country's seal returns.

It calls the jurisdiction at fixed points, in this order:

| Step | When | Saudi Arabia | United Arab Emirates |
|---|---|---|---|
| `refuseIssued()` | Before anything, for an invoice that is not a draft | `FatooraException` | `IssuanceRefusedException` |
| `prepare()` | Before the lock. Throwing refuses, and nothing is written | Country check, kill switches, clearance, ZATCA's rules, signing credentials | Kill switch, clearance |
| `refuseIssuedMeanwhile()` / `finished()` | Under the lock, when another request issued the draft first | Refuses, or returns the finished result on a reissue | Returns the stored document |
| `beforeNumbering()` | Under the lock, before the number | Allocates the counter for the EGS unit | Nothing |
| `seal()` | Once the number and moment exist | Hash, XAdES signature and QR code | Mandatory fields, scenarios and totals. Problems refuse and roll the number back |
| `afterIssued()` | After the invoice is saved as issued | Records the hash-chain entry | Stores the Peppol document |
| `afterCommit()` | After the transaction | Nothing | `invoice.issued`, then the queued send |

Filing is not part of the seam. It belongs to the module's transmission. A
ZATCA clearance or report answers at once and runs after issuing, in
`InvoiceIssuer`, which also raises `invoice.issued` for Saudi invoices. A
Peppol document is handed to the accredited service provider and settled
later by callbacks.

## What every country shares

- **Drafting.** `InvoiceDrafter` makes the draft for both. Only the request
  rules and the country's columns (`jurisdiction_data`, a UAE invoice's due
  date) are country-specific, and both come from the module's
  `InvoiceRules`.
- **Issuing.** `IssuingLifecycle`, described above, reached through the
  engine.
- **Numbering.** `InvoiceNumbering` allocates from the organization's
  sequence. The date in a number and the period a counter restarts at are read
  on the organization's own clock (`App\Support\CountryClock`: Riyadh for
  `SA`, Dubai for `AE`).
- **Clearance.** `Organization\Services\FilingClearance` decides whether an
  organization is approved, not suspended and on a plan. It and the kill
  switch refuse with `App\Support\Errors\ComplianceException`, the neutral
  base of `FatooraException`, so every country catches it without naming
  another; the error codes, statuses and messages are the taxonomy's, as
  before.
- **Notification.** `Invoice\Services\IssuanceNotifier` raises
  `invoice.issued`.

## Country checks in shared code

`tests/Feature/Architecture/CountryCheckTest.php` lists every literal country
comparison (`country === 'AE'` and the like) left in `app/` outside the
country modules and the engine. The list must match exactly, so it can only
shrink, and it is now empty: issuing, the rules, the invoice pages, the
reports, the dashboards, the setup checklist and the billing organization's
buyer all ask a port. A new comparison fails the build; put the rule behind a
port instead.

Some shared code still names module classes rather than comparing country
codes. `ModuleBoundaryTest` lists each file, and the list only shrinks. What
is left: the admin console's ZATCA panels (`AdminController`,
`AdminDashboardController`, `PlatformStatus`, `IssueDetector`,
`MetricsCollector`, `QueueReport`, `OrganizationReport`), branches and their
credentials (`Branch`, `BranchService`), the models' links into a module
(`Invoice`'s submissions, `Customer`'s PINT codes, the address DTO on
`Organization`), the API's error rendering (`ApiResponse`,
`bootstrap/app.php`), the providers, events and routes that register each
module, the Saudi-only PDF and print templates (which `InvoicePdf` and the
print action render once the country's `assertShareable()` allows), and the
two new-invoice forms and their line rows. The sidebar's Submissions and
Certificates links still show for every country; they open the Saudi screens.

## Later phases

Not built yet:

- **Moves.** `CountryClock` and the remaining checks read the module's
  `timezone()` and `currency()`, the country APIs route through the engine,
  and the admin console's ZATCA panels move behind ports of their own.

## Routing key

`ComplianceProfile.jurisdiction` is an ISO 3166-1 alpha-2 code. An
organization has at most one profile per jurisdiction, because
`(org_id, jurisdiction)` is unique. Who must file is decided by each
country's law, not by this platform:

- **Saudi Arabia** applies to persons resident in Saudi Arabia (see
  [the Saudi README](../sa/README.md)).
- **The UAE** applies to anyone doing business in the UAE (see
  [the UAE README](../ae/README.md)).

See also [ADDING-A-JURISDICTION.md](ADDING-A-JURISDICTION.md),
[ORGANIZATION-MODEL.md](ORGANIZATION-MODEL.md) and
[ACCESS.md](ACCESS.md), which answers whether an organization may use a
feature in its country and why not.

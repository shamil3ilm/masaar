# Adding a jurisdiction

This page covers what adding a country involves today. Read
[JURISDICTION-ROUTING.md](JURISDICTION-ROUTING.md) first. The short version
is that each country is a module plugged into the compliance engine in
`app/Domains/Compliance/Engine/`. The module implements the engine's
`Jurisdiction` contract and registers itself from its own service provider.
Every country issues through the same `IssuingLifecycle`, with an
`IssuingJurisdiction` of its own for its steps.

## The module

Every country, whichever model it follows, starts here. The module lives in
`app/Domains/Compliance/Countries/<Country>/`, with the country's English
name in PascalCase (`SaudiArabia`), and copies the Saudi module's top level:
`Module.php`, `ModuleServiceProvider.php`, `Issuing/`, `Document/`,
`Filing/`, `Rules/`, `Reports/`, then Laravel's own folders. Add `Signing/`
or `Onboarding/` only if the authority requires them. See
[Where a country lives](JURISDICTION-ROUTING.md#where-a-country-lives) for
what goes in each.

1. **Module class.** `Module.php`, class `Module`, implements
   `Engine\Contracts\Jurisdiction`, as `Countries\SaudiArabia\Module` and
   `Countries\UnitedArabEmirates\Module` do:
   - `code()`, `name()`, `timezone()`, `currency()` and `filingSystem()`;
   - `enabled()`, as `JurisdictionAvailability::enabled($this->code(), <the
     country's configured default>)`, so the admin console's Countries page
     can switch it like every other;
   - `readiness()`, what an operator checks before switching it on, from
     configuration only;
   - `issuingStep()`, the country's `IssuingJurisdiction`;
   - `transmission()`, an `Engine\Contracts\Transmission` that issues
     through the lifecycle and files or sends, answering an
     `Engine\DTOs\IssueOutcome`;
   - `notices()`, the portal's wording after issuing.
2. **Registration.** In `ModuleServiceProvider.php`, register it with the
   registry, as `Countries\SaudiArabia\ModuleServiceProvider` and
   `Countries\UnitedArabEmirates\ModuleServiceProvider` do:

   ```php
   $this->app->afterResolving(JurisdictionRegistry::class, function (JurisdictionRegistry $registry): void {
       $registry->register('<CC>', fn (): Module => $this->app->make(Module::class));
   });
   ```

   A new provider goes in `bootstrap/providers.php`, imported with an alias
   because every module's provider has the same name.
3. **Nothing else in shared code.** The pipeline, the portal's issue action
   and billing reach the new country through `ComplianceEngine`. Do not add
   a `country === '<CC>'` branch anywhere. `CountryCheckTest` fails the
   build on one.

Later phases add more ports to `Jurisdiction` (rules, presentation and
reports). Until then, the steps below still name the places outside the
module that a country has to touch.

## First, decide which of two models the country follows

| Model | Example | What the platform builds |
|---|---|---|
| **The authority's own API** (clearance or reporting) | Saudi Arabia (ZATCA) | The client for the authority, onboarding and credentials, and whatever the authority requires on each document: signing, hashing, QR codes. Lives in the country's module, like `app/Domains/Compliance/Countries/SaudiArabia/` |
| **Peppol through an accredited service provider** | United Arab Emirates (PINT AE) | The PINT document and the country's field rules, in the country's module (`app/Domains/Compliance/Countries/UnitedArabEmirates/`). Transport goes through the shared network, `app/Domains/Compliance/Networks/Peppol/` |

## A country on Peppol PINT

A Peppol country is a module under `Countries/` plus the shared
`Networks/Peppol/`. The network is written for any country and names none
(`ModuleBoundaryTest` keeps it so):

- the transport: `AspGateway` and its gateways, `PeppolSender`,
  `DocumentStatus`, `SendPeppolDocument`, the status and inbound callbacks,
  `peppol:poll-status` and `peppol:resend`;
- receiving: `InboundDocuments`, which stores a received document in the
  country of the profile holding its endpoint (any country in
  `config/peppol.php`), and `ReceivedReader`;
- the `peppol_documents` table (`PeppolDocument`), which has a `country`
  column.

The PINT document itself is still in the UAE's module, because its types are
PINT AE's: the `JurisdictionProfile` contract and the `PintDocumentCategory`
and `PintTaxCategory` enums it names, the PINT DTOs (AED line amounts, the
BTAE-02 flags), `PintUblBuilder`, `PintMapper` and `MandatoryFields`, which
read `PintAe` constants such as reserved endpoints, flags, emirates and code
lists. A second country has a module of its own, with its own `Module`,
`ModuleServiceProvider`, `Issuing/IssuingStep` and `Issuing/InvoiceIssuer`
calling the same `IssuingLifecycle`, and the parts above that it shares move
to `Networks/Peppol/` once they take a profile instead of PINT AE's values.

Steps:

1. **Profile.** Add `Rules/Pint<Cc>` implementing `JurisdictionProfile`:
   - customization and profile IDs for each document kind;
   - type codes;
   - the endpoint scheme;
   - allowed tax categories;
   - the tax currency and standard rate.

   Read every value from the published PINT specification for that country,
   not from memory.
2. **Rules.** Write the country's field and scenario checks in the style of
   `MandatoryFields`: one named check per official field, and the schematron
   rule named beside any conditional one.
3. **Generalise.** Move the UAE-specific parts listed above behind the
   profile.
4. **Switch.** Add `countries.<CC>.enabled` (from `PEPPOL_<CC>_ENABLED`) to
   `config/peppol.php`, to `.env.example` and to `docker/.env.template`, off by
   default. It is the default the module passes to `JurisdictionAvailability`;
   `CompanyProfileRules::countries()` and the Countries page follow it with
   nothing more to add.
5. **API.** Add `/api/compliance/<cc>/*` routes behind a gate middleware like
   `uae.invoicing`, with `jurisdiction.available` on the routes that start new
   work, and add them to `docs/openapi.yaml`.
6. **Conformance.** Extend `PintConformanceTest` and the CI job to run that
   country's official artefacts. Pin each downloaded file by hash.

## A country with its own authority API

1. Create `app/Domains/Compliance/Countries/<Country>/` in the layout above,
   with the client, DTOs, exceptions, jobs, models and services the
   authority needs. Use the Saudi module as the reference for retries,
   idempotency, offline handling (`Filing/`) and certificate storage
   (`Signing/`).
2. Implement `App\Domains\Invoice\Contracts\IssuingJurisdiction` as
   `Issuing/IssuingStep.php`, as `Countries\SaudiArabia\Issuing\IssuingStep`
   and `Countries\UnitedArabEmirates\Issuing\IssuingStep` do. Put
   the country's checks in `prepare()` and its document in `seal()`, and
   pass `FilingClearance` and the kill switch in `prepare()` like the others.
   Both refuse with the neutral `App\Support\Errors\ComplianceException`,
   which a country catches without naming another's exception.
3. Write the issuer and routes (`/api/compliance/<cc>/*`). The issuer calls
   `IssuingLifecycle::issue()` with that jurisdiction, as each module's
   `Issuing\InvoiceIssuer` does, and the module's transmission calls the issuer. Reuse `InvoiceDrafter` and `IssuanceNotifier` rather than copying
   them; numbering already comes with the lifecycle.
4. Add the authority's credentials and switch to `config/` and the env
   templates, named by purpose, with a production guard for anything required.
5. Add a migration for any submission records. Compliance records are never
   deleted, so use `restrictOnDelete` on their foreign keys.

## For every country

- **Organization.** Add the country's seller details and their validation to
  `CompanyProfileRules`, stored on that country's `ComplianceProfile` (see
  [ORGANIZATION-MODEL.md](ORGANIZATION-MODEL.md)).
- **Clock.** Add the country's time zone to `App\Support\CountryClock`, as
  well as to the module's `timezone()`. Without it, the date in an invoice
  number and the period its counter restarts at are read on Riyadh's clock.
  A later phase has `CountryClock` read the module instead.
- **Docs.** Add `docs/<cc>/README.md`, `COMPLIANCE-RULES.md` and
  `INTEGRATION-GUIDE.md`, as `docs/ae/` and `docs/sa/` have.
  - README files carry no dates. Regulatory timelines go in
    `COMPLIANCE-RULES.md`, with their source.
- **Tests.** Extend `tests/Feature/Compliance/Engine/` so the engine routes
  the new country and refuses it while switched off. Add
  `tests/Feature/Compliance/<Country>/` (unit tests in
  `tests/Unit/Domains/Compliance/Countries/<Country>/`), as Saudi Arabia's
  are, covering the following:
  - rules refused at draft time and at send time;
  - one document per invoice;
  - retries that never file twice;
  - another tenant's records answering `404`;
  - the official conformance run.
- **Naming.** Use the authority's own vocabulary, which is exempt from
  plain-English renaming (see [NAMING.md](../NAMING.md)). Never use a vendor
  or provider name in code, config, env or docs.

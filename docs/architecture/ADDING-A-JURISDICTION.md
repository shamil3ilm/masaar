# Adding a jurisdiction

Audience: engineers adding a country module or a Peppol country.

Read [JURISDICTION-ROUTING.md](JURISDICTION-ROUTING.md) first: it lists the
engine's ports and how the two existing countries answer them. A country is
a module in `app/Domains/Compliance/Countries/<Country>/` (English name in
PascalCase) that implements `Engine\Contracts\Jurisdiction`, registers
itself, and issues through the shared `IssuingLifecycle`.

## First, decide the model

| Model | Example | What the platform builds |
|---|---|---|
| The authority's own API (clearance or reporting) | Saudi Arabia | The client, onboarding and credentials, and whatever each document needs (signing, hashing, QR), all in the module |
| Peppol through an accredited service provider | United Arab Emirates | The PINT document and the country's field rules in the module; transport through the shared `Networks/Peppol/` |

## Every country

1. **Module.** `Module.php` implementing `Jurisdiction`, copying the Saudi
   module's top level (`Issuing/`, `Document/`, `Rules/`, `Reports/`,
   `Presentation/`; `Filing/`, `Signing/`, `Onboarding/` only if the
   authority needs them). Implement every port the interface lists - the
   interface, `app/Domains/Compliance/Engine/Contracts/Jurisdiction.php`, is
   the checklist - and `enabled()` as
   `JurisdictionAvailability::enabled($this->code(), <configured default>)`
   so the console's Countries page switches it like the others.
2. **Registration.** In `ModuleServiceProvider.php`:

   ```php
   $this->app->afterResolving(JurisdictionRegistry::class, function (JurisdictionRegistry $registry): void {
       $registry->register('<CC>', fn (): Module => $this->app->make(Module::class));
   });
   ```

   Add it to `bootstrap/providers.php` with an alias (every module's
   provider has the same name). Only the Saudi module is `default: true`.
3. **Issuing step.** `Issuing/IssuingStep.php` implementing
   `Invoice\Contracts\IssuingJurisdiction`: checks in `prepare()` (with
   `FilingClearance` and the kill switch, refusing with the neutral
   `App\Support\Errors\ComplianceException`), the document in `seal()`.
   `Issuing/InvoiceIssuer` calls `IssuingLifecycle::issue()`; the module's
   transmission calls the issuer. Reuse `InvoiceDrafter` and
   `IssuanceNotifier`.
4. **Nothing in shared code.** The pipeline, portal and billing reach the
   country through `ComplianceEngine` and the ports. No
   `country === '<CC>'` anywhere: `CountryCheckTest` fails the build.
5. **Views.** In `resources/views/countries/<kebab-name>/`, named
   `countries.<country>.<view>`: navigation, dashboard, reports,
   `invoice/heading|actions|panel|create`, `print/page|pdf`, and the forms
   the rules ports name. New strings need Arabic in `lang/ar.json`.
6. **Clock.** Add the time zone to `App\Support\CountryClock` as well as
   `timezone()`; `CountryClock` does not read the module yet, and without it
   numbering dates fall back to Riyadh.
7. **Switch and config.** The country's enabled default and credentials in
   `config/`, `.env.example` and `docker/.env.template`, named by purpose,
   with a production guard for anything required.
8. **Records.** Submission records are compliance records: never deleted,
   `restrictOnDelete` foreign keys, declared in
   `tests/Feature/Architecture/TableTimestamps.php`.

## A Peppol country

The network names no country (`ModuleBoundaryTest`): `AspGateway` and its
gateways, `PeppolSender`, `DocumentStatus`, `SendPeppolDocument`, the
callbacks, receiving (`InboundDocuments` files a received document under the
country of the profile holding its endpoint) and `peppol_documents` (with a
`country` column).

The PINT document is still in the UAE module, because its types are PINT
AE's: `Contracts\JurisdictionProfile`, the `PintDocumentCategory` and
`PintTaxCategory` enums, the PINT DTOs, `PintUblBuilder`, `PintMapper` and
`MandatoryFields`, which read `PintAe` constants. A second Peppol country:

1. **Profile.** `Rules/Pint<Cc>` implementing `JurisdictionProfile`:
   customization and profile IDs, type codes, endpoint scheme, tax
   categories, tax currency and standard rate - from the published PINT
   specification, not from memory.
2. **Rules.** One named check per official field, in the style of
   `MandatoryFields`, with the schematron rule id beside each conditional one.
3. **Generalise.** Move the UAE-only parts above to `Networks/Peppol/` once
   they take a profile instead of PINT AE's values.
4. **Switch.** `countries.<CC>.enabled` (`PEPPOL_<CC>_ENABLED`, off) in
   `config/peppol.php`.
5. **API.** `/api/compliance/<cc>/*` behind a gate like `uae.invoicing`, with
   `jurisdiction.available` on routes that start work, and in
   `docs/openapi.yaml`.
6. **Conformance.** Extend `PintConformanceTest` and its CI job to the
   country's official artefacts, each pinned by hash.

## An authority-API country

Use the Saudi module as the reference for retries, idempotency and offline
handling (`Filing/`, see [FILING-STATES.md](FILING-STATES.md)) and for
certificate storage (`Signing/`). Add `/api/compliance/<cc>/*` routes, the
authority's credentials in config, and a migration for submission records.

## Tests and documents

- `tests/Feature/Compliance/Engine/` reads the registry, so a new country is
  held to `PresentationPortsTest` and `FeatureSupportTest` the day it
  registers. Extend the engine tests so it routes and refuses while off.
- Add `tests/Feature/Compliance/<Country>/` (and
  `tests/Unit/Domains/Compliance/Countries/<Country>/`): rules refused at
  draft and send time on the API and the pipeline; one document per
  invoice; retries that never file twice; another tenant's records `404`;
  the official conformance run.
- The ratchets - `ModuleBoundaryTest`, `CrossDomainModelTest`,
  `CountryCheckTest`, `JurisdictionIsolationTest` - hold the module from its
  first commit ([DOMAINS.md](DOMAINS.md)); the
  [testing guide](../developers/TESTING-AND-GATES.md) describes them.
- Add `docs/compliance/<cc>/README.md` and `RULES.md`, as the existing
  countries have. READMEs carry no dates; timelines go in `RULES.md` with
  their source.
- Use the authority's vocabulary ([CONVENTIONS.md](../developers/CONVENTIONS.md)); never a vendor
  or provider name in code, config, env or docs.

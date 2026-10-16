# Adding a jurisdiction

This page covers what adding a country involves today. Read
[JURISDICTION-ROUTING.md](JURISDICTION-ROUTING.md) first. The short version
is that each country has its own routes, and every country issues through
the same `IssuingLifecycle`. A new country implements `IssuingJurisdiction`
for its own steps and keeps the shared lifecycle as it is.

## First, decide which of two models the country follows

| Model | Example | What the platform builds |
|---|---|---|
| **The authority's own API** (clearance or reporting) | Saudi Arabia (ZATCA) | The client for the authority, onboarding and credentials, and whatever the authority requires on each document: signing, hashing, QR codes. Lives in a domain folder like `app/Domains/Compliance/Fatoora/` |
| **Peppol through an accredited service provider** | United Arab Emirates (PINT AE) | The PINT document and the country's field rules. Transport goes through the existing `AspGateway`. Lives in `app/Domains/Compliance/Peppol/` |

## A country on Peppol PINT

The Peppol module is built for the UAE. The parts written so another country
can use them are:

- the `JurisdictionProfile` contract;
- the PINT DTOs;
- `PintUblBuilder`;
- the transport (`AspGateway`, `PeppolSender`, `DocumentStatus`, the
  callbacks, `peppol:poll-status` and `peppol:resend`);
- the `peppol_documents` table, which has a `country` column.

Several parts are still tied to the UAE, and need to be generalised before a
second country can use them:

- `PintMapper`, `MandatoryFields` and `CreateUaeInvoiceRequest` read
  `PintAe` constants, such as reserved endpoints, flags, emirates and code
  lists.
- `InboundDocuments` stores every received document as `AE`.
- `UaeInvoicing` checks `peppol.countries.AE.enabled` and `country === 'AE'`.
- `UaeJurisdiction` and `UaeInvoiceIssuer` issue UAE invoices. A second
  country implements its own `IssuingJurisdiction` and calls the same
  `IssuingLifecycle`.

Steps:

1. **Profile.** Add `Profiles/Pint<Cc>` implementing `JurisdictionProfile`:
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
   default. Allow the country in `CompanyProfileRules::countries()` only while
   the switch is on.
5. **API.** Add `/api/compliance/<cc>/*` routes behind a gate middleware like
   `uae.invoicing`, and add them to `docs/openapi.yaml`.
6. **Conformance.** Extend `PintConformanceTest` and the CI job to run that
   country's official artefacts. Pin each downloaded file by hash.

## A country with its own authority API

1. Create `app/Domains/Compliance/<Authority>/` with the client, DTOs,
   exceptions, jobs, models and services the authority needs. Use the Fatoora
   domain as the reference for retries, idempotency, offline handling and
   certificate storage.
2. Implement `App\Domains\Invoice\Contracts\IssuingJurisdiction` in the
   country's folder, as `ZatcaJurisdiction` and `UaeJurisdiction` do. Put
   the country's checks in `prepare()` and its document in `seal()`, and
   pass `FilingClearance` and the kill switch in `prepare()` like the others.
3. Write the issuer and routes (`/api/compliance/<cc>/*`). The issuer calls
   `IssuingLifecycle::issue()` with that jurisdiction, as `UaeInvoiceIssuer`
   does. Reuse `InvoiceDrafter` and `IssuanceNotifier` rather than copying
   them; numbering already comes with the lifecycle.
4. Add the authority's credentials and switch to `config/` and the env
   templates, named by purpose, with a production guard for anything required.
5. Add a migration for any submission records. Compliance records are never
   deleted, so use `restrictOnDelete` on their foreign keys.

## For every country

- **Organization.** Add the country's seller details and their validation to
  `CompanyProfileRules`, stored on that country's `ComplianceProfile` (see
  [ORGANIZATION-MODEL.md](ORGANIZATION-MODEL.md)).
- **Clock.** Add the country's time zone to `App\Support\CountryClock`.
  Without it, the date in an invoice number and the period its counter
  restarts at are read on Riyadh's clock.
- **Docs.** Add `docs/<cc>/README.md`, `COMPLIANCE-RULES.md` and
  `INTEGRATION-GUIDE.md`, as `docs/ae/` and `docs/sa/` have.
  - README files carry no dates. Regulatory timelines go in
    `COMPLIANCE-RULES.md`, with their source.
- **Tests.** Add `tests/Feature/Compliance/<Domain>/` covering the following:
  - rules refused at draft time and at send time;
  - one document per invoice;
  - retries that never file twice;
  - another tenant's records answering `404`;
  - the official conformance run.
- **Naming.** Use the authority's own vocabulary, which is exempt from
  plain-English renaming (see [NAMING.md](../NAMING.md)). Never use a vendor
  or provider name in code, config, env or docs.

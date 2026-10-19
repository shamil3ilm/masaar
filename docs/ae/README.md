# United Arab Emirates — e-invoicing (Peppol PINT AE)

This page explains how UAE e-invoicing works, what this platform does and what
an accredited service provider does, and how to switch it on and test it. The
rules the platform checks are in [COMPLIANCE-RULES.md](COMPLIANCE-RULES.md).
The API is described step by step in [INTEGRATION-GUIDE.md](INTEGRATION-GUIDE.md).

## How UAE e-invoicing works

The UAE uses a decentralised model with five parties, called corners. In the
official texts this is the "Decentralised Continuous Transaction Control and
Exchange" (DCTCE) model:

| Corner | Who | Role |
|---|---|---|
| 1 | Supplier (an organization on this platform) | Calculates every value on the invoice, collects the buyer's Peppol identifier, and hands the complete invoice to its service provider |
| 2 | Supplier's accredited service provider | Validates the document, sends it to the buyer's provider, and reports the tax data to the authority |
| 3 | Buyer's accredited service provider | Validates the document, confirms it to corner 2, passes it to the buyer, and reports it to the authority |
| 4 | Buyer | Receives the invoice |
| 5 | Federal Tax Authority | Receives the tax data and confirms it was reported |

The authority has **no API that a taxpayer calls directly**. Every document
travels through an accredited service provider, and the Ministry of Finance
publishes the list of accredited providers. A business uses **one** provider
for both sending and receiving. It registers that provider through the
authority's own portal, and the provider issues the business's Peppol
participant identifier.

Documents are UBL 2.1 XML that follows the Peppol PINT AE specification. A UAE
document has no QR code, no cryptographic stamp from the authority and no hash
chain. None of the ZATCA machinery (counters, hashing, signing, QR codes) runs
for a UAE invoice.

## Who does what

| This platform (corner 1) | The service provider (corners 2 and 3) |
|---|---|
| Keeps the seller's UAE details: TRN or TIN, Peppol endpoint, emirate, legal registration | Issues the participant identifier when the business is onboarded |
| Drafts invoices, numbers them, issues them | Checks that the buyer's participant identifier can be reached |
| Calculates every amount, including the amounts in AED | Moves the document over the Peppol network, encrypted |
| Checks the Ministry's mandatory-field list and the scenario rules before anything is stored | Validates the document again |
| Builds the PINT AE XML and keeps it exactly as it was sent | Reports the tax data to the authority |
| Sends the document to the provider, retries when there is no verdict, and records what the provider reports back | Forwards the buyer's confirmation and the authority's confirmation |
| Stores documents received for the organization | Delivers documents that other suppliers send to the organization |

The legal obligation stays with the taxpayer, even where a provider does the
work.

## Supported documents

| Kind | API `document_category` / `document_type` | Type code |
|---|---|---|
| Tax invoice | `tax` / `invoice` | 380 |
| Tax credit note | `tax` / `credit_note` | 381 |
| Commercial invoice (exempt or out-of-scope supplies, or a seller not registered for VAT) | `commercial` / `invoice` | 480 |
| Commercial credit note | `commercial` / `credit_note` | 81 |
| Self-billed tax invoice (the buyer issues it for the supplier) | `self_billing` / `invoice` | 389 |
| Self-billed tax credit note | `self_billing` / `credit_note` | 261 |

The following are not supported:

- **Debit notes.** The UAE has no e-invoicing debit note. To increase an
  amount, issue a new invoice.
- **B2C.** Supplies to consumers are outside UAE e-invoicing. The UAE API has
  no simplified invoice type, and every document names its buyer.
- **Self-billed commercial documents.** These do not exist under UAE rules.

Every document uses one of these eight scenarios, set by
`transaction_flags`: free zone, deemed supply, margin scheme, summary invoice,
continuous supply, disclosed agent, e-commerce, or export. The rules for each
scenario are in [COMPLIANCE-RULES.md](COMPLIANCE-RULES.md).

## Identifiers

| Identifier | Shape | Where it comes from |
|---|---|---|
| TRN (Tax Registration Number) | 15 digits, starts with `1`, ends with `03` | The authority, when the business registers for VAT |
| TIN (Tax Identification Number) | 10 digits, starts with `1` | The first 10 digits of the TRN. A business with no TRN registers with the authority to get one |
| Peppol participant identifier (endpoint) | `0235:` followed by the 10-digit TIN | Issued during onboarding with the service provider. The platform stores it exactly as issued and never works it out from the TRN |

Three buyer endpoints are reserved:

- `0235:9900000098`: a domestic buyer that is not yet on e-invoicing.
- `0235:9900000097`: any deemed supply.
- `0235:9900000099`: an export buyer that has no Peppol identifier.

A document sent to one of these is reported to the authority and never
exchanged. Once the provider takes it, its status is `not_exchanged`, and it
is done once its report is confirmed (UAE Electronic Invoicing Guidelines
v1.1, p23, p26, p28).

## Enabling it

UAE e-invoicing is off unless a deployment switches it on. It has the same
switch as Saudi Arabia: a platform admin turns it on or off on the admin
console's **Countries** page (`/admin/countries`, permission
`platform.configure`), and that switch wins over `PEPPOL_AE_ENABLED`. The
variable is only the default until someone throws the switch. Each switch is
stored with who threw it and when, and is on the audit record.

While the UAE is off:

- nothing new is issued there. The portal says so on the issue button and in
  a banner on every page. The API's routes that start new work
  (`POST /api/compliance/ae/invoices`, `.../send`) and the pipeline answer
  `422 JURISDICTION_UNAVAILABLE`;
- sign-up and the company country list do not offer it;
- what was already issued is not stranded. Ready documents are still sent,
  status is still polled, callbacks are still recorded, and the read routes
  and portal screens stay open.

| Variable | Purpose |
|---|---|
| `PEPPOL_AE_ENABLED` | The default: `true` issues in the UAE and lets organizations register there until an admin switches it on the Countries page |
| `PEPPOL_ASP_BASE_URL` | The service provider's API. Leave it empty to run with no provider (see below) |
| `PEPPOL_ASP_CLIENT_ID`, `PEPPOL_ASP_CLIENT_SECRET` | OAuth2 client credentials for the provider |
| `PEPPOL_ASP_WEBHOOK_SECRET` | The shared secret that signs the provider's callbacks. With no secret set, every callback is refused |
| `PEPPOL_QUEUE` | The queue for outgoing documents (default `peppol`). The container runs a dedicated worker for it, so a slow UAE provider never delays ZATCA work |

If `PEPPOL_ASP_BASE_URL` is set, production refuses to start unless the client
ID, client secret and webhook secret are also set, whether or not the UAE is
on yet: an admin can switch it on later without a restart.

To set up an organization:

1. Register it with country `AE`.
2. An administrator saves the seller details with
   `PUT /api/compliance/ae/profile`. These are the name, address, emirate,
   TRN (or TIN), the endpoint the provider issued, and the legal
   registration. This creates the organization's active UAE compliance
   profile.

After that, the organization can draft and send invoices.

The provider integration (`HttpAspGateway`) is a generic REST shape: an OAuth2
token, `POST /documents`, `GET /documents/{id}`, and signed callbacks. No
provider has been chosen yet. Once one is chosen, check its API against
`HttpAspGateway`, `WebhookSignature` and `WebhookPayload` in
`app/Domains/Compliance/Networks/Peppol/Gateways/`, and adapt those three classes only.

## Testing without a provider

- **No provider configured** (`PEPPOL_ASP_BASE_URL` empty). Invoices are still
  issued, and their documents are built, checked and stored. Each document
  stays in status `ready`, with no send attempt counted, and the XML can be
  downloaded from `GET /api/compliance/ae/documents/{id}/xml` and uploaded
  through a provider's portal by hand. The platform cannot learn about a manual
  upload, so the document's status stays `ready`. Callbacks are refused, and
  `peppol:poll-status` does not run.
- **The service-provider simulator** (`PEPPOL_GATEWAY=simulator`, local and
  staging only). It plays a provider inside the application. It checks every
  document against the official rules, then calls back through the signed
  webhooks: accepted, delivered, reported, or a configured failure. It also
  delivers inbound documents. Production refuses to boot with it. How to
  switch it on and what to show are in [SIMULATOR.md](SIMULATOR.md).
- **Automated tests** replace the provider with `FakeAspGateway`. It returns
  scripted answers, records every call, and checks callbacks with the real
  signature code. The UAE's tests are in
  `tests/Feature/Compliance/UnitedArabEmirates/`, and the shared transport's
  in `tests/Feature/Compliance/Networks/Peppol/`.
- **Conformance with the official rules.** `PintConformanceTest` runs the
  published PINT AE validation artefacts (billing and self-billing
  schematrons, and the UBL 2.1 schema when it is present) over documents this
  platform builds and over the official examples. To run it locally:
  1. Unzip the billing `resources.zip` under `billing/` and the self-billing
     one under `selfbilling/` (and optionally UBL 2.1 under `ubl21/`), all in
     one directory.
  2. Point `PEPPOL_ARTEFACTS_PATH` at that directory.
  3. Point `PEPPOL_XSLT_JAR` at an XSLT 2.0 processor jar.

  If either variable is unset, the test is skipped.
- **CI.** The `PINT AE conformance` job downloads the artefacts on every push
  that touches the application and checks each file against a pinned hash. It
  fails if the suite is skipped, or if an upstream file changes before the
  change has been reviewed.

## Current status

The following is built and tested:

- Seller details
- Drafting and issuing invoices
- The mandatory-field and scenario checks
- PINT AE XML for all six document kinds
- Sending through a provider, with retries and an exactly-once guarantee
- Status callbacks and polling
- Inbound documents
- The tenant API
- The portal: UAE company settings, a UAE invoice form, the e-invoice's
  status and XML on the invoice page, a received-invoices page, and UAE
  self sign-up
- The official-rules conformance run in CI
- A service-provider simulator for demos and end-to-end tests, judged by the
  official rules, and the admin console's Peppol page

The following is not done:

- **No service provider is connected.** The HTTP gateway follows a generic
  shape and has not run against a real provider.
- **No PDF for a UAE invoice.** The e-invoice is the XML; the PDF, print and
  email actions are Saudi-only and are hidden for UAE invoices.
- **Status changes for manually uploaded documents are not recorded.**
- **Received documents are stored, not processed.** They are kept and listed
  exactly as they arrived, but are not turned into purchase records.

## Further reading

- [COMPLIANCE-RULES.md](COMPLIANCE-RULES.md): the rules and the official sources behind them
- [INTEGRATION-GUIDE.md](INTEGRATION-GUIDE.md): the API, callbacks and operator commands
- [SIMULATOR.md](SIMULATOR.md): the service-provider simulator and demo mode
- [PROVIDER-ONBOARDING.md](PROVIDER-ONBOARDING.md): connecting a real service provider, what to ask, and the gaps
- [Jurisdiction routing](../architecture/JURISDICTION-ROUTING.md): how the UAE and Saudi flows sit in the code
- Peppol PINT AE specification: https://docs.peppol.eu/poac/ae/pint-ae/ (self-billing: https://docs.peppol.eu/poac/ae/pint-ae-sb/)
- Ministry of Finance e-invoicing pages: https://mof.gov.ae/einvoicing/

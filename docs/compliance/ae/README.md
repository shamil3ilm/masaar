# United Arab Emirates — e-invoicing (Peppol PINT AE)

**Audience:** engineers, operators and compliance reviewers setting up or
checking UAE e-invoicing.

How UAE e-invoicing works, what the platform does and what the accredited
service provider does, and how to switch it on. The rules are in
[RULES.md](RULES.md); connecting a provider is in
[PROVIDER-ONBOARDING.md](PROVIDER-ONBOARDING.md); document statuses are in
[FILING-STATES.md](../../architecture/FILING-STATES.md#4-the-uae-network-document).

## The model

The UAE uses a decentralised five-corner model (DCTCE):

| Corner | Who | Role |
|---|---|---|
| 1 | Supplier (an organization on the platform) | Calculates every value, collects the buyer's Peppol identifier, hands the complete document to its provider |
| 2 | Supplier's accredited service provider (ASP) | Validates, sends to the buyer's provider, reports the tax data to the authority |
| 3 | Buyer's ASP | Validates, confirms to corner 2, passes to the buyer, reports to the authority |
| 4 | Buyer | Receives the invoice |
| 5 | Federal Tax Authority | Receives the tax data and confirms it |

The authority has no API a taxpayer calls: every document travels through
an ASP. A business appoints **one** ASP for sending and receiving, through
EmaraTax, and the ASP issues its Peppol participant identifier (Guidelines
v1.1, 6.1, 9). "Compliance will be achieved through working with an ASP",
and the obligation "rests with any Person who is subject to Electronic
Invoicing" (6.1). The platform is not an ASP: it is the taxpayer's own
system, corner 1.

Documents are UBL 2.1 in PINT AE form, with no QR code, authority stamp or
hash chain (Guidelines 5.3). None of the Saudi machinery runs for them.

| The platform (corner 1) | The ASP (corners 2 and 3) |
|---|---|
| Keeps the seller's TRN or TIN, endpoint, emirate and legal registration | Issues the participant identifier |
| Drafts, numbers and issues invoices; computes every amount, AED included | Moves the document over Peppol |
| Checks the mandatory fields and scenario rules before anything is stored | Validates it again |
| Builds the PINT AE XML and keeps it exactly as sent | Reports the tax data to the authority |
| Sends it, retries without a verdict, records every answer | Forwards the buyer's and the authority's confirmations |
| Stores documents received for the organization | Delivers documents other suppliers send |

## Documents

| Kind | `document_category` / `document_type` | Type code |
|---|---|---|
| Tax invoice / credit note | `tax` / `invoice`, `credit_note` | 380 / 381 |
| Commercial invoice / credit note (exempt or out-of-scope supplies, or a seller not registered for VAT) | `commercial` / `invoice`, `credit_note` | 480 / 81 |
| Self-billed tax invoice / credit note | `self_billing` / `invoice`, `credit_note` | 389 / 261 |

No debit notes (an increase is a new invoice), no B2C, no self-billed
commercial documents. Scenarios are set by `transaction_flags`; see
[RULES.md](RULES.md#transaction-type-flags).

| Identifier | Shape | Source |
|---|---|---|
| TRN | 15 digits, starts `1`, ends `03` | The authority, on VAT registration |
| TIN | 10 digits, starts `1`: the TRN's first 10 | The authority |
| Participant identifier | `0235:` + the TIN | The ASP; stored as issued, never derived |

Reserved buyer endpoints (`0235:9900000097` deemed supply, `...98` buyer not
yet on e-invoicing, `...99` export with no identifier) are reported and never
exchanged: status `not_exchanged` (Guidelines v1.1, p23, p26, p28).

**Printed tax invoice (PDF).** Every issued UAE invoice has a page, a PDF and
a receipt, drawn from the same PINT document as the XML so the two cannot
differ (`PrintedInvoice`). It is marked *required* for a buyer at the
reserved endpoint or not found on the network — such a buyer needs a regular
tax invoice as well as the e-invoice (Guidelines v1.1, 10.2.2) — and a *copy*,
printed on request, for a buyer on the network, whose tax invoice remains the
e-invoice (6.2). Before the e-invoicing start date it is the tax invoice
(VAT Executive Regulation Art. 59). It is refused for a draft and for a
rejected e-invoice.
<!-- app/Domains/Compliance/Countries/UnitedArabEmirates/Presentation/{PrintedInvoice,Presentation}.php;
     resources/views/countries/united-arab-emirates/print/ -->


## Before the e-invoicing start date

Each organization has its own **e-invoicing start date**, set on the
portal's Company page (`POST /portal/company/einvoicing-start`) or by a
platform admin on the organization's console page. It is never derived:
the roll-out phase depends on revenue, which the platform does not know,
and anyone may start voluntarily (Guidelines v1.1, 8.2, 8.3).
`EinvoicingPhases` is shown only as a hint.
<!-- Countries/UnitedArabEmirates/Support/EinvoicingStart.php; Rules/EinvoicingPhases.php -->

- **No date, or issued before it:** an ordinary tax invoice (VAT Executive
  Regulation Art. 59). Its document is built, checked and kept with the
  final status `not_transmitted`, and never sent, then or later.
- **From the date:** the document is created `ready` and sent. Issuing is
  refused while no provider appointment is recorded (Guidelines 6.1).
<!-- Issuing/IssuingStep.php prepare(), finished(); Document/PeppolPreparer.php -->

The date is read on the UAE's clock (Asia/Dubai). Each change is audited
(`uae.einvoicing_start.changed`), and reminders go 90, 30 and 7 days before
it (`uae:remind-provider-appointments`).

## Enabling it

UAE e-invoicing is off unless switched on. A platform admin switches it on
the console's Countries page (`/admin/countries`, permission
`platform.configure`); that switch wins over `PEPPOL_AE_ENABLED`, which is
only the default. While it is off, nothing new is issued (new-work routes
and the pipeline answer `422 JURISDICTION_UNAVAILABLE`) and sign-up does not
offer it, but documents already issued are still sent, polled and shown.

| Variable | Purpose |
|---|---|
| `PEPPOL_AE_ENABLED` | Default for the country switch |
| `PEPPOL_ASP_BASE_URL` | The partner provider's API; empty means no provider |
| `PEPPOL_ASP_CLIENT_ID`, `PEPPOL_ASP_CLIENT_SECRET` | OAuth2 client credentials |
| `PEPPOL_ASP_WEBHOOK_SECRET` | Signs the provider's callbacks; unset, every callback is refused |
| `PEPPOL_QUEUE` | Queue for outgoing documents (default `peppol`), with its own worker |
| `PEPPOL_RETRY_WINDOW_HOURS` | Hours a document is retried without a verdict (default 72) |
| `PEPPOL_AE_APPOINTMENT_DEADLINE` | Last date to appoint a provider, for reminders; empty sends none |

With `PEPPOL_ASP_BASE_URL` set, production refuses to start until the client
ID, secret and webhook secret are set.
<!-- config/peppol.php; app/Providers/AppServiceProvider.php production checks -->

Setting up an organization:

1. Register it with country `AE`.
2. Save the seller details: `PUT /api/compliance/ae/profile` or the
   portal's Company page.
3. Record the provider appointed through EmaraTax
   (`POST /portal/company/service-provider`, kept in
   `provider_appointments`).
4. Set the e-invoicing start date.

Each organization's documents go through the provider `AspGatewayResolver`
resolves for it: the platform's partner (`peppol.providers.platform`)
unless the customization `einvoicing.service_provider` names another
configured provider. Only the partner is configured.

## Testing without a provider

- **No provider** (`PEPPOL_ASP_BASE_URL` empty): documents are built,
  checked and stored as `ready`, and their XML can be downloaded
  (`GET /api/compliance/ae/documents/{id}/xml`) for manual upload.
- **Simulator** (`PEPPOL_GATEWAY=simulator`, local and staging; production
  refuses to boot with it): plays a provider, judges each document with the
  official rules and calls back through the signed webhooks. See the
  simulator guide (SIMULATOR.md).
- **Automated tests** use `FakeAspGateway`: tests in
  `tests/Feature/Compliance/UnitedArabEmirates/` and
  `tests/Feature/Compliance/Networks/Peppol/`.
- **Official rules:** `PintConformanceTest` runs the PINT AE billing and
  self-billing schematrons (and UBL 2.1 when present) over built documents
  and the official examples. Set `PEPPOL_ARTEFACTS_PATH` (with `billing/`,
  `selfbilling/`, optionally `ubl21/`) and `PEPPOL_XSLT_JAR`; unset, it is
  skipped. CI's `PINT AE conformance` job downloads hash-pinned artefacts
  and fails if the suite is skipped.

## Not done

- No real provider is connected; `HttpAspGateway` follows a generic shape.
- Status changes for manually uploaded documents are not recorded.
- Received documents are stored and listed, not turned into purchases.

## Further reading

- Peppol PINT AE: https://docs.peppol.eu/poac/ae/pint-ae/ (self-billing: https://docs.peppol.eu/poac/ae/pint-ae-sb/)
- Ministry of Finance e-invoicing pages: https://mof.gov.ae/einvoicing/
- [Jurisdiction routing](../../architecture/JURISDICTION-ROUTING.md)

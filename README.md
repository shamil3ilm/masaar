# Masaar — GCC E-Invoicing Compliance Platform

A multi-jurisdiction e-invoicing compliance API platform for GCC businesses.

## Supported Jurisdictions

| Country | Authority | System | Status |
|---------|-----------|--------|--------|
| 🇸🇦 Saudi Arabia | ZATCA | Fatoora Phase 2 | 🟡 Passes ZATCA's SDK validator — schema, EN 16931, Schematron and, signed with the authority's own certificate, its certificate, QR, signed-properties digest and PIH checks — and ZATCA's developer sandbox accepts the full onboarding round trip. Simulation and production need a registered taxpayer, see [Conformance](#conformance) |
| 🇦🇪 UAE | Ministry of Finance / FTA | Peppol PINT AE, through an accredited service provider | 🚧 In development — tax, commercial and self-billed invoices and credit notes pass the official PINT AE rules, and drafting, sending and receiving through a service provider are built and tested, in the API and the portal. Switched off by default (`PEPPOL_AE_ENABLED`); no service provider is connected yet |
| 🇶🇦 Qatar | GTA | — | 📋 Planned |

> **Production readiness.** The Saudi pipeline — UBL generation, ICV/PIH hash
> chaining, XAdES signing, TLV QR, CSID onboarding and submission — is built,
> and so are invoice PDFs: PDF/A-3b with the signed XML embedded and a PAdES
> stamp. Signatures verify against the certificate in the document, the QR's
> tags match the document beside them, the UBL totals satisfy their own
> arithmetic, documents pass ZATCA's own validator, and PDFs pass an
> independent PDF/A validator — see [Conformance](#conformance).
>
> Not yet established: signing keys are encrypted at rest by the platform rather
> than held in a managed KMS, and nothing has been filed in ZATCA's simulation
> or production environments, which issue an OTP only to a registered
> taxpayer. See [docs/sa/STATUS.md](docs/sa/STATUS.md) before deploying to
> production.

## What It Does

**For an ERP or billing system — the API.** Submit an invoice and get back the
signed XML, hash, QR and ZATCA's answer; or drive each step yourself. Clearance
and reporting, offline queueing and retry, credit and debit notes, PDFs, and
signed webhooks. Described in [`docs/openapi.yaml`](docs/openapi.yaml).

**For a taxpayer — the portal.** Create and issue invoices, start a credit or
debit note from the invoice it corrects, keep customers and products, download
or email the PDF, read the VAT summary and export it as CSV, onboard with
ZATCA, and raise support tickets. In Arabic and English, with two-factor
sign-in and per-organization roles.

**For the operator — the console.** Organizations, licenses and renewals,
billing, the support desk, and filing-deadline alerts across every taxpayer.

## Repository Structure

```
├── app/             ← Compliance API (Laravel 12, PHP 8.4), by domain
└── docs/
    ├── sa/          ← Saudi Arabia (Fatoora) documentation
    ├── ae/          ← UAE (Peppol PINT AE) documentation
    ├── qa/          ← Qatar (GTA) documentation — planned
    └── architecture/ ← Platform design docs
```

## Quick Start

**Requires PHP 8.4+** — the dependency tree (Symfony 8, Pest 4) will not install
on 8.3 or below.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate
php artisan db:seed
npm install && npm run build   # the portal's stylesheet and scripts
php artisan serve
```

`php artisan platform:create-admin` creates the first operator for the console.

Run the test suite:

```bash
php artisan test
```

## Conformance

The test suite checks that the code does what the code intends; it cannot by
itself establish what the authority requires, such as BT-23 reading
`reporting:1.0`. ZATCA's own Java SDK does, and `ZatcaConformanceTest` runs it
over documents this platform generates — the UBL 2.1 schema, the CEN EN 16931
rules, ZATCA's Schematron, and the PIH check.

The SDK is a licensed download that cannot be committed, so these tests skip
unless you point at one:

```bash
ZATCA_SDK_PATH=/path/to/zatca-einvoicing-sdk-Java-238-R3.4.8 php artisan test
```

Point it at the directory holding `Apps/` and `Data/`. Java 17+ must be on the
PATH. Without it the suite still runs; the conformance tests report as skipped,
which is why CI is unaffected.

What it covers: the six documents ZATCA's compliance check requires (standard
and simplified — invoice, credit note, debit note), all five BT-3 sub-type
flags, zero-rated, exempt and out-of-scope lines, a healthcare supply billed to
a citizen, foreign currency, a document-level discount, and a discount spread
across two tax categories. One test validates ZATCA's own sample invoice, so a
broken harness reports itself as broken rather than as a broken invoice.

Notes on running the SDK:

- It reads `SDK_CONFIG` and `FATOORA_HOME`. Without `SDK_CONFIG` it dies in
  `Config.readResourcesPaths` with a NullPointerException that reads like a
  malformed invoice and is not. The test sets both per-process.
- **Do not run its `install.bat`.** It executes `SETX PATH ""` and then rebuilds
  PATH from `%PATH%`, which truncates at 1024 characters and can destroy a
  Windows PATH. Nothing needs it; the two variables above are the whole setup.
- Moving the SDK after installing it leaves absolute paths in
  `Configuration/config.json` pointing at the old location. Repoint them or the
  validator fails on every document.

With `ZATCA_SDK_PATH` set the suite signs with ZATCA's own pre-production
certificate and its matching key - the pair the SDK ships for sample taxpayer
`399999999900003` - so the certificate check, the QR that embeds it and the
PIH chain are all checked and all pass. Without it the suite falls back to a
self-signed pair, and those three cannot be checked either way.

One check stays excluded: `signatureValue`. Not because this platform cannot
satisfy it - the SDK fails its own shipped samples on it, because those files
were pretty-printed after they were signed, so the `SignedInfo` in the file is
not the one that was signed. A check that rejects the authority's reference
documents says nothing about ours, so `SignatureVerifiesTest` establishes the
same property with OpenSSL instead: the signature verifies over the
canonicalised `SignedInfo` with the certificate the document carries, on the
document as finally emitted. That needs no SDK, so it runs on every push.

It is named individually in `businessRules()` rather than filtered by prefix,
so a second cannot join it unnoticed. See
[docs/sa/HASHING-AND-SIGNING.md](docs/sa/HASHING-AND-SIGNING.md) for every
digest and how each was established.

What no conformance run can establish: that the Fatoora portal will issue a
CSID for a real taxpayer's certificate request, and that ZATCA's simulation and
production APIs will clear or report that taxpayer's documents. Both need a
registered taxpayer. The developer sandbox accepts the whole round trip -
onboarding and all six compliance documents - and CI repeats it on a schedule.

### Invoice PDFs

A PDF invoice is PDF/A-3b with the signed XML embedded and, once the
organization holds a certificate, a PAdES stamp (ZATCA's Security Features
standard). `PdfaConformanceTest` hands stamped and unstamped PDFs to an
independent PDF/A validator; point `PDFA_VALIDATOR_PATH` at its command-line
launcher to run it locally. CI installs the validator, checks its publisher's
signature, and runs the suite on every push.

The stamp carries the certificate chain up to ZATCA's root when
`ZATCA_CA_CHAIN_PATH` names a PEM bundle of ZATCA's CA certificates for the
environment in use; without it the stamp carries the device certificate alone.

## Scheduled Tasks

One cron entry drives all of them:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` prints the live schedule, which is the only thing
that cannot drift. What it currently registers:

| When | Task |
|------|------|
| Every 5 min | `fatoora:process-offline --limit=50` — drain the offline queue |
| Every 10 min | `fatoora:recover-submissions` — re-dispatch submissions left in flight |
| Every 15 min | `compliance:index-health --alert` |
| Hourly | `fatoora:overdue-submissions --alert` — invoices past their filing deadline |
| Hourly | `license:cleanup-rate-limits` |
| Hourly | `license:report-usage` |
| Daily 00:00 | `license:check-expiration` |
| Daily 01:00 | `backup:clean` — prune archives by the retention policy |
| Daily 01:30 | `backup:run` — encrypted database and credential-store backup |
| Daily 04:00 | `compliance:cleanup-offline-queue` |
| Daily 04:30 | `model:prune` — webhook delivery logs past retention |
| Daily 06:00 | `backup:monitor` — alert when the newest backup is stale |
| Daily 08:00 | `fatoora:check-certificate --notify` |
| Daily 09:00 | `billing:remind-renewals` — email admins whose plan ends soon |
| Weekly Sun 02:00 | `fatoora:verify-hash-chain` |
| Monthly 1st 03:00 | `compliance:partition-maintenance --create-future --months-ahead=2` |

Backup setup and the restore procedure are in
[docs/operations/BACKUP-AND-RESTORE.md](docs/operations/BACKUP-AND-RESTORE.md).

A taxpayer is connected once, from the portal's ZATCA page or through
`/api/compliance/onboarding`, with an OTP from the Fatoora portal. The
`fatoora:onboard` command rehearses the same exchange as ZATCA's test taxpayer
and so runs against the developer sandbox only:

```bash
php artisan fatoora:onboard --step=full --otp=<otp> --target=sandbox
```

The remaining commands are operator tools rather than scheduled work:
`fatoora:generate-csr`, `fatoora:validate`, `fatoora:sandbox-test`,
`license:generate`, `license:status`, `platform:openapi` and
`platform:rotate-credential-key`.

## Documentation

- [Saudi Arabia (Fatoora)](docs/sa/README.md)
- [Saudi Arabia - implementation status](docs/sa/STATUS.md) - what has been
  established against the authority, what has not, and what is blocked on a
  registered taxpayer
- [UAE (Peppol PINT AE)](docs/ae/README.md)
- [Qatar (GTA)](docs/qa/README.md)
- [Adding a Jurisdiction](docs/architecture/ADDING-A-JURISDICTION.md)
- [Backup and restore](docs/operations/BACKUP-AND-RESTORE.md)

## Integrating

The API is described by [`docs/openapi.yaml`](docs/openapi.yaml), generated
from the route table and checked against it in CI, so any OpenAPI generator
produces a complete client in the language you use. There are no official
client libraries.

Webhooks are signed; [docs/WEBHOOKS.md](docs/WEBHOOKS.md) shows verification in
PHP, Node.js, Python, Java, C# and Go. The
[partner integration guide](docs/PARTNER-INTEGRATION-GUIDE.md) walks through an
ERP integration end to end, over the licence-key API under `/api/v1`.

## License

Commercial use requires registration. See [LICENSE](LICENSE) and [TERMS](TERMS.md).

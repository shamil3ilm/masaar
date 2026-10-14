# Masaar — GCC E-Invoicing Compliance Platform

A multi-jurisdiction e-invoicing compliance API platform for GCC businesses.

## Supported Jurisdictions

| Country | Authority | System | Status |
|---------|-----------|--------|--------|
| 🇸🇦 Saudi Arabia | ZATCA | Fatoora Phase 2 | 🟡 Passes ZATCA's SDK validator — schema, EN 16931, Schematron and, signed with the authority's own certificate, its certificate, QR, signed-properties digest and PIH checks. Live submission outstanding, see [Conformance](#conformance) |
| 🇦🇪 UAE | FTA | Peppol PINT AE | 🚧 In development |
| 🇶🇦 Qatar | GTA | — | 📋 Planned |

> **Production readiness.** The Saudi pipeline — UBL generation, ICV/PIH hash
> chaining, XAdES signing, TLV QR, CSID onboarding and submission — is built.
> Signatures verify against the certificate in the document, the QR's tags match
> the document beside them, the UBL totals satisfy their own arithmetic, and
> documents pass ZATCA's own validator — see [Conformance](#conformance).
>
> Not yet established: signing keys are encrypted at rest by the platform rather
> than held in a managed KMS, and no live submission to ZATCA's production API
> has been made. See [docs/sa/STATUS.md](docs/sa/STATUS.md) before deploying to
> production.

## Repository Structure

```
├── app/             ← Compliance API (Laravel 12, PHP 8.4), by domain
├── sdks/            ← Client SDKs (PHP, TypeScript, Python, Java, Go, ...)
└── docs/
    ├── sa/          ← Saudi Arabia (Fatoora) documentation
    ├── ae/          ← UAE (FTA) documentation
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
php artisan serve
```

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
CSID for a certificate request, and that ZATCA's API will clear or report a
document. Both need a real taxpayer.

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
| Daily 06:00 | `backup:monitor` — alert when the newest backup is stale |
| Daily 08:00 | `fatoora:check-certificate --notify` |
| Weekly Sun 02:00 | `fatoora:verify-hash-chain` |
| Monthly 1st 03:00 | `compliance:partition-maintenance --create-future --months-ahead=2` |

Backup setup and the restore procedure are in
[docs/BACKUP-AND-RESTORE.md](docs/BACKUP-AND-RESTORE.md).

Run once when connecting a taxpayer, not scheduled:

```bash
php artisan fatoora:onboard --step=full --otp=<otp> --target=simulation
```

The remaining commands are operator tools rather than scheduled work:
`fatoora:generate-csr`, `fatoora:validate`, `fatoora:sandbox-test`,
`license:generate`, `license:status`, `masaar:openapi`, `masaar:sdk-types` and
`masaar:rotate-credential-key`.

## Documentation

- [Saudi Arabia (Fatoora)](docs/sa/README.md)
- [Saudi Arabia - implementation status](docs/sa/STATUS.md) - what has been
  established against the authority, what has not, and what is blocked on a
  registered taxpayer
- [UAE (FTA)](docs/ae/README.md)
- [Qatar (GTA)](docs/qa/README.md)
- [Adding a Jurisdiction](docs/architecture/ADDING-A-JURISDICTION.md)
- [Backup and restore](docs/BACKUP-AND-RESTORE.md)

## SDKs

Client libraries live in [`sdks/`](sdks/). **None are published to a package
registry yet** — use them by vendoring the source. They are hand-written against
the API rather than generated, so treat the HTTP API and
[`docs/openapi.yaml`](docs/openapi.yaml) as authoritative where they disagree.

| SDK | Status | Notes |
|-----|--------|-------|
| [Java](sdks/java/) | 🟢 Most complete | Typed models, resource classes, exception hierarchy |
| [PHP](sdks/php/) | 🟡 Single-file client | Covers a subset of the API surface |
| [TypeScript](sdks/typescript/) | 🟡 Single-file client | Intended Tier-1 target |
| [Python](sdks/python/) | 🟡 Single-file client | |
| [Rust](sdks/rust/) · [Go](sdks/go/) · [Kotlin](sdks/kotlin/) · [Swift](sdks/swift/) · [.NET](sdks/dotnet/) · [Ruby](sdks/ruby/) · [Dart](sdks/dart/) | 🟠 Skeleton | One client file each; no tests, unverified against a live API |
| JavaScript | 🔴 Not implemented | Use the TypeScript SDK — it compiles to JavaScript |

Known gaps: the SDKs cover a subset of the API and most carry no automated
tests. The intended direction is to generate them from the OpenAPI
specification rather than maintain eleven by hand.

## License

Commercial use requires registration. See [LICENSE](LICENSE) and [TERMS](TERMS.md).

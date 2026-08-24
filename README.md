# Masaar — GCC E-Invoicing Compliance Platform

A multi-jurisdiction e-invoicing compliance API platform for GCC businesses.

## Supported Jurisdictions

| Country | Authority | System | Status |
|---------|-----------|--------|--------|
| 🇸🇦 Saudi Arabia | ZATCA | Fatoora Phase 2 | 🟡 Feature complete — not yet validated against ZATCA |
| 🇦🇪 UAE | FTA | Peppol PINT AE | 🚧 In development (mandate: 2027-01-01) |
| 🇶🇦 Qatar | GTA | — | 📋 Planned |

> **Production readiness.** The Saudi pipeline — UBL generation, ICV/PIH hash
> chaining, XAdES signing, TLV QR, CSID onboarding and submission — is built,
> and the parts of it that can be checked without ZATCA's own fixtures are:
> signatures verify against the certificate in the document, the QR's tags match
> the document beside them, and the UBL totals satisfy their own arithmetic.
>
> That wording used to be "built and covered by tests", which was true and
> misleading. Tests existed; they did not check these things, and until recently
> the signature was computed over an empty string, certificate requests could not
> be generated, and every tax subtotal declared a base that included its own tax.
>
> Still outstanding: validation against ZATCA's published conformance fixtures —
> which is what settles the encodings a self-consistency check cannot — and
> signing keys are not yet held in a managed KMS.
> See [`docs/audit/09-WORK-MAP.md`](docs/audit/09-WORK-MAP.md) for the current
> gap list before deploying to production.

## Repository Structure

```
Masaar/
├── platform/        ← This directory: Compliance API (Laravel 12, PHP 8.4)
├── erp/             ← ERP backend (separate repo, future git submodule)
├── sdks/            ← Client SDKs (PHP, TypeScript, Python, Java, Go, ...)
└── docs/
    ├── sa/          ← Saudi Arabia (Fatoora) documentation
    ├── ae/          ← UAE (FTA) documentation
    ├── qa/          ← Qatar (GTA) documentation — planned
    └── architecture/ ← Platform design docs
```

> **Note:** The `platform/` directory is the root of this repository.  
> The monorepo parent (`Masaar/`) is `C:/laragon/www/Masaar` on the development machine.

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

## Documentation

- [Saudi Arabia (Fatoora)](docs/sa/README.md)
- [UAE (FTA)](docs/ae/README.md)
- [Qatar (GTA)](docs/qa/README.md)
- [Adding a Jurisdiction](docs/architecture/ADDING-A-JURISDICTION.md)
- [Design Spec](docs/superpowers/specs/2026-04-02-masaar-multi-jurisdiction-design.md)

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

Known gaps, tracked in [`docs/audit/`](docs/audit/): the SDKs cover a subset of
the API, carry no automated tests, and several still use the platform's former
name in class names. The intended direction is to generate them from the OpenAPI
specification rather than maintain eleven by hand.

## License

Commercial use requires registration. See [LICENSE](LICENSE) and [TERMS](TERMS.md).

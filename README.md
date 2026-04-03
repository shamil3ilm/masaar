# Masaar — GCC E-Invoicing Compliance Platform

A production-ready multi-jurisdiction e-invoicing compliance API platform for GCC businesses.

## Supported Jurisdictions

| Country | Authority | System | Status |
|---------|-----------|--------|--------|
| 🇸🇦 Saudi Arabia | ZATCA | Fatoora Phase 2 | ✅ Production Ready |
| 🇦🇪 UAE | FTA | Peppol PINT AE | 🚧 In Development (mandate: 2027-01-01) |
| 🇶🇦 Qatar | GTA | — | 📋 Planned |

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

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate
php artisan db:seed
php artisan serve
```

## Documentation

- [Saudi Arabia (Fatoora)](docs/sa/README.md)
- [UAE (FTA)](docs/ae/README.md)
- [Qatar (GTA)](docs/qa/README.md)
- [Adding a Jurisdiction](docs/architecture/ADDING-A-JURISDICTION.md)
- [Design Spec](docs/superpowers/specs/2026-04-02-masaar-multi-jurisdiction-design.md)

## SDKs

Available in `sdks/`: PHP, TypeScript, Python, Java, Go, Kotlin, Dart, Swift, Ruby, Rust, .NET

## License

Commercial use requires registration. See [LICENSE](LICENSE) and [TERMS](TERMS.md).

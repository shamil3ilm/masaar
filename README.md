# Masaar — GCC E-Invoicing & Compliance Engine

A high-performance, bilingual (Arabic & English) compliance and e-invoicing platform designed for businesses in Saudi Arabia and the GCC.

## Compliance Support

| Country | Authority | Protocol & Standards | Status |
|---|---|---|---|
| **Saudi Arabia** | ZATCA (Fatoora) | Phase 2 Clearance & Reporting, UBL 2.1, ECDSA secp256k1, SHA-256 Hash Chaining, Cryptographic QR | Production Ready |
| **United Arab Emirates** | MoF / FTA | Peppol PINT AE (AS4 / ASP Integration) | Pre-mandate & Pipeline Complete |

## Capabilities

- **Portal-First Invoicing**: Web portal for issuing standard and simplified invoices, credit/debit notes, summary invoices, and PDF/A-3 exports with embedded signed XML.
- **Partner & Pipeline API**: Idempotent REST API (`/api/v1`) and signed webhooks for ERP, POS, and e-commerce integrations.
- **Cryptographic Security**: Per-device CSID certificates, private key management, audit trail with tamper-evident SHA-256 hash chaining.
- **Books & Accounting**: Customer receivables, ageing reports, deemed supplies, input VAT, and draft VAT returns.
- **Multi-Tenancy**: Organization and branch-level isolation enforced at database and application levels.

## Quick Start

Requires PHP 8.4+ and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate
npm install && npm run build
php artisan serve
```

Initialize the first administrator:
```bash
php artisan platform:create-admin <email>
```

Run test suite:
```bash
php artisan test
```

## Documentation

Full architectural guides, API specs, and deployment documentation are available in [docs/README.md](docs/README.md).

## License

Commercial use requires registration. See [LICENSE](LICENSE) and [TERMS](TERMS.md).

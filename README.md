# GCC E-Invoicing Compliance Platform

Audience: anyone meeting the project for the first time.

A bilingual (English and Arabic) e-invoicing platform for businesses in Saudi
Arabia and the UAE. It issues, signs and files tax invoices with the tax
authority, and keeps the records an accountant needs to close the month.

| Country | Authority | How invoices are filed |
|---|---|---|
| Saudi Arabia | ZATCA | Fatoora Phase 2: clearance and reporting |
| UAE | Ministry of Finance / FTA | Peppol PINT AE, through an accredited service provider (switched off by default) |

## Who it is for

- **Businesses** - a portal to issue invoices, credit and debit notes, print
  them on A4 or a receipt roll, keep customers and products, and run Books:
  purchases, input VAT, the VAT return draft, receivables and statements.
- **Integrators** - an API and signed webhooks for an ERP, store or billing system.
- **Operators** - a console to approve companies, watch filing, run approvals,
  bill customers and reconcile payments.

## What it does

- ZATCA onboarding per device, with sandbox, simulation and production environments per company.
- UBL 2.1 invoices, signatures, hash chain and QR codes; PDF/A-3 invoices with the signed XML embedded.
- Clearance and reporting with offline queueing and automatic retry.
- Branches and tills, each with its own numbering and printer.
- Returns, exchanges and replacements; summary invoices from delivery notes; contracts billed in stages with retention.
- Books for vouchers, deemed supplies and bad-debt relief, beside the VAT return draft.
- Roles (owner, admin, member, accountant, viewer) and an optional second approver for notes.
- UAE: a start date per company, pre-mandate mode and service-provider appointment.
- Platform finance: billing, payments, bank reconciliation, expenses and reports.
- Every setting has a platform default with organization and personal overrides.

## Quick start

Requires PHP 8.4 or later.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate
npm install && npm run build
php artisan serve
```

Create the first console operator with `php artisan platform:create-admin <email>`.

Run the tests with `php artisan test`.

## Documentation

Everything is listed by audience in [docs/README.md](docs/README.md).

## License

Commercial use requires registration. See [LICENSE](LICENSE) and [TERMS](TERMS.md).

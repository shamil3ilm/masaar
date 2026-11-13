# Changelog

All notable changes to the Masaar platform are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0]

### Added
- **Saudi ZATCA Phase 2 Compliance**: Cryptographic invoice signing, UBL 2.1 document generation, ECDSA secp256k1 keys, and QR code embedding.
- **Clearance & Reporting Pipeline**: Real-time ZATCA clearance for standard B2B invoices and 24-hour reporting for simplified B2C invoices.
- **Tenant Isolation & Security**: Global tenant scopes (`BelongsToTenant`) and dedicated cryptographic audit chain key (`AUDIT_CHAIN_KEY`).
- **Partner & Pipeline API**: Versioned idempotent API endpoints (`/api/v1`) for system integration with signed outbound webhooks.
- **Web Portal & Books**: Invoicing portal, customer receivables, ageing analysis, VAT return drafting, and branch management.
- **UAE Peppol Integration**: PINT AE document generation and PEPPOL network pipeline readiness.

### Changed
- Refactored controllers to adhere to clean architecture standards with dedicated FormRequests and service decoupling.
- Centralized asynchronous webhook dispatching under strict tenant context resolution (`TenantResolver::runAs`).
- Standardized API response and error envelopes with categorized error codes and idempotency replay protection.

# Changelog

All notable changes to Masaar will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-02-03

### Added

- **Core Features**
  - ZATCA Phase 2 compliant e-invoicing API
  - UBL 2.1 XML generation with all required elements
  - XAdES-BES digital signatures (ECDSA secp256k1)
  - TLV-encoded QR codes (9 tags for Phase 2)
  - Multi-tenant architecture with organization isolation
  - JWT and API key authentication
  - Webhook notifications with HMAC-SHA256 signatures
  - ZATCA business rules validation (BR-KSA-*)

- **ZATCA Integration**
  - CSR generation for onboarding
  - Compliance CSID (CCSID) request flow
  - Production CSID (PCSID) request flow
  - Invoice clearance (B2B) support
  - Invoice reporting (B2C) support
  - Credit and debit note support

- **Infrastructure**
  - Production-ready Dockerfile (PHP 8.2-FPM Alpine)
  - Docker Compose for local and production deployment
  - GitHub Actions CI/CD for automated Docker builds
  - Prometheus metrics endpoint (`/api/metrics`)
  - Grafana dashboard configuration
  - K6 load testing scripts

- **SDKs**
  - Python SDK
  - TypeScript/JavaScript SDK
  - PHP Legacy SDK (Laravel 8+)
  - Java SDK
  - Go SDK
  - Ruby SDK
  - .NET SDK
  - Kotlin SDK
  - Dart/Flutter SDK
  - Swift SDK
  - Rust SDK

- **Admin Features**
  - Web-based admin dashboard
  - Organization management
  - Queue monitoring
  - Audit logging

- **Documentation**
  - OpenAPI 3.0 specification
  - Deployment guides
  - Security policy
  - Contributing guidelines

### Security

- Input validation and sanitization
- SQL injection prevention (parameterized queries)
- XSS prevention with output encoding
- CSRF protection
- Rate limiting on all endpoints
- Audit logging for compliance actions
- Private key encryption at rest

---

## Version History

| Version | Release Date | Status |
|---------|--------------|--------|
| 1.0.0   | 2026-02-03   | Current |

[Unreleased]: https://github.com/shamil3ilm/zatca/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/shamil3ilm/zatca/releases/tag/v1.0.0

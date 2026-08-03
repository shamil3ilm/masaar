# Masaar Platform Audit — 2026-08-03

A comprehensive audit of the Masaar GCC e-invoicing compliance platform, covering architecture, security, code quality, performance, maintainability, testing, documentation, developer experience, product strategy and roadmap.

**Scope:** `Masaar` (primary), `erp-backend` and `erp-frontend` (integration context only).
**Method:** read-only static investigation. No code was modified.

---

## Start here

**[00-EXECUTIVE-SUMMARY.md](00-EXECUTIVE-SUMMARY.md)** — verdict, the four issues that block everything, the three structural problems, and the roadmap at a glance.

## Full documents

| Document | Covers | Read if you are… |
|---|---|---|
| [00-EXECUTIVE-SUMMARY.md](00-EXECUTIVE-SUMMARY.md) | Verdict · top risks · scorecard · roadmap summary · repo relationships | anyone |
| [01-DISCOVERY-AND-ARCHITECTURE.md](01-DISCOVERY-AND-ARCHITECTURE.md) | System map · request lifecycle · invoice & submission lifecycle · multi-jurisdiction routing · architecture scorecard · anti-patterns · config/data/deployment | an engineer or architect |
| [02-SECURITY-AUDIT.md](02-SECURITY-AUDIT.md) | 4 Critical · 6 High · 8 Medium · 4 Low findings, each with evidence, impact and remediation · controls done well · remediation sequence · compliance exposure · **what this audit did not cover** | **read first** — security lead, eng lead |
| [03-QUALITY-PERFORMANCE-MAINTAINABILITY.md](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md) | Code quality defects · essential vs accidental complexity · per-class disposition · performance findings · maintainability scores · **the unified consistency standard** · testing strategy | an engineer |
| [04-PRODUCT-DX-AND-GAP-ANALYSIS.md](04-PRODUCT-DX-AND-GAP-ANALYSIS.md) | Documentation audit · DX assessment · feature gap (MVP/important/nice-to-have/future) · competitive analysis · personas · positioning · pricing · adoption | product, founder |
| [05-TARGET-ARCHITECTURE-AND-ROADMAP.md](05-TARGET-ARCHITECTURE-AND-ROADMAP.md) | NFR targets · target architecture · modularization · compatibility & SDK strategy · build-vs-buy · risk register · 4-phase roadmap · prioritized backlog · production readiness gates | eng lead, founder |

---

## The one-paragraph version

Masaar is a strong ZATCA compliance core wrapped in an unfinished platform shell. The domain logic — XML generation, ICV/PIH hash chaining, TLV QR encoding, XAdES signing, CSID onboarding — is genuinely good, and the `ComplianceEngine` abstraction that makes multi-jurisdiction support additive is the best design decision in the project and the basis of a defensible market position. What surrounds it is not production-grade: four unauthenticated entry points, three parallel licensing systems, two competing authentication stacks, 44 service classes against 29 test files, and eleven empty SDK directories the README advertises as shipped. **The correct response is remediation and simplification, not a rewrite** — the four Critical security findings can be closed in under two weeks, and roughly eight weeks of focused work would produce a defensible, honestly-documented, self-service-evaluable product.

---

## Findings index

| ID | Severity | Finding | Document |
|---|:---:|---|---|
| C-1 | 🔴 Critical | `/admin/*` web console has no authentication | [02 §1](02-SECURITY-AUDIT.md#-c-1--the-admin-web-console-has-no-authentication) |
| C-2 | 🔴 Critical | `/portal/*` reads tenant from `?org_id=`, unauthenticated | [02 §1](02-SECURITY-AUDIT.md#-c-2--customer-portal-reads-tenant-identity-from-a-query-parameter-unauthenticated) |
| C-3 | 🔴 Critical | API key **and secret** accepted from query parameters | [02 §1](02-SECURITY-AUDIT.md#-c-3--api-key-and-secret-accepted-from-url-query-parameters) |
| C-4 | 🔴 Critical | `TenantIsolationGuard` is dead code; no isolation defence-in-depth | [02 §1](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) |
| H-1 | 🟠 High | Signing keys encrypted with `APP_KEY` on local disk | [02 §2](02-SECURITY-AUDIT.md#-h-1--signing-keys-are-encrypted-with-app_key-on-a-local-filesystem-disk) |
| H-2 | 🟠 High | SSRF via certificate revocation checking | [02 §2](02-SECURITY-AUDIT.md#-h-2--ssrf-via-certificate-revocation-checking) |
| H-3 | 🟠 High | CRL revocation check is silently non-functional | [02 §2](02-SECURITY-AUDIT.md#-h-3--crl-revocation-check-is-silently-non-functional) |
| H-4 | 🟠 High | Revocation and CSR paths shell out to external binaries | [02 §2](02-SECURITY-AUDIT.md#-h-4--revocation-and-csr-paths-shell-out-to-external-binaries) |
| H-5 | 🟠 High | OpenSSL config injection during CSR generation | [02 §2](02-SECURITY-AUDIT.md#-h-5--openssl-configuration-injection-during-csr-generation) |
| H-6 | 🟠 High | Unauthenticated Prometheus metrics endpoint | [02 §2](02-SECURITY-AUDIT.md#-h-6--unauthenticated-prometheus-metrics-endpoint) |
| M-1…M-8 | 🟡 Medium | XML hardening · dual authz models · rate limiting · usage writes · key hashing · debug defaults · CSRF · audit gaps | [02 §3](02-SECURITY-AUDIT.md#3-medium-findings) |
| L-1…L-4 | 🔵 Low | Guard state · guard logic · dependency scanning · log sanitisation | [02 §4](02-SECURITY-AUDIT.md#4-low-findings) |
| Q-1…Q-10 | — | Code quality defects | [03 §1.2](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#12-defects-and-weaknesses) |
| P-1…P-8 | — | Performance findings | [03 §3](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#3-performance-review) |
| A-1…A-8 | — | Architectural recommendations | [01 §7](01-DISCOVERY-AND-ARCHITECTURE.md#7-summary-of-architectural-recommendations) |
| R-1…R-16 | — | Risk register | [05 §6](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#6-risk-register) |

---

## Limitations

This audit is static and partial. Read **[02 §8 — What this audit did not cover](02-SECURITY-AUDIT.md#8-what-this-audit-did-not-cover)** before treating any section as exhaustive. In particular: no dynamic testing, no dependency CVE scan, no infrastructure review, no security review of `erp-backend`, and no cryptographic conformance verification of the ZATCA signature construction against the official specification.

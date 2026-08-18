# Portfolio Audit — Masaar · erp-backend · erp-frontend

A comprehensive audit of the three-repository portfolio, covering architecture, security, code quality, performance, maintainability, testing, documentation, developer experience, product strategy, reusable-asset extraction and a consolidated cleanup plan.

**Method:** read-only static investigation. **No code was modified in either pass.**

| Pass | Date | Scope | Documents |
|---|---|---|---|
| **1** | 2026-08-03 | `Masaar` in depth; other repos as integration context | 00–05 |
| **2** | 2026-08-18 | `erp-backend` + `erp-frontend`; knowledge mining; cleanup plan | 06–08 |

---

## Start here

- **"Where do I start?"** → **[09-WORK-MAP.md](09-WORK-MAP.md)** ← current state + sequenced tasks
- **New to this?** → [00-EXECUTIVE-SUMMARY.md](00-EXECUTIVE-SUMMARY.md)
- **Security detail** → [02-SECURITY-AUDIT.md](02-SECURITY-AUDIT.md)

> ⚠️ **Documents 00–08 describe the 2026-08-03 / 2026-08-18 snapshots.** 22 commits of remediation have since landed: **all four Critical findings and four of six High findings are closed.** [09-WORK-MAP.md](09-WORK-MAP.md) supersedes the sequencing in [08-CLEANUP-PLAN.md](08-CLEANUP-PLAN.md) and lists what is verified closed vs. still open.

## Full documents

| Document | Covers | Read if you are… |
|---|---|---|
| [00-EXECUTIVE-SUMMARY.md](00-EXECUTIVE-SUMMARY.md) | Verdict · top risks · scorecard · roadmap summary · repo relationships | anyone |
| [01-DISCOVERY-AND-ARCHITECTURE.md](01-DISCOVERY-AND-ARCHITECTURE.md) | System map · request lifecycle · invoice & submission lifecycle · multi-jurisdiction routing · architecture scorecard · anti-patterns | an engineer or architect |
| [02-SECURITY-AUDIT.md](02-SECURITY-AUDIT.md) | Masaar: 4 Critical · 6 High · 8 Medium · 4 Low, each with evidence and remediation · controls done well · **what this audit did not cover** | **read first** — security lead |
| [03-QUALITY-PERFORMANCE-MAINTAINABILITY.md](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md) | Code quality · essential vs accidental complexity · performance · **the unified consistency standard** · testing strategy | an engineer |
| [04-PRODUCT-DX-AND-GAP-ANALYSIS.md](04-PRODUCT-DX-AND-GAP-ANALYSIS.md) | Documentation audit · DX assessment · feature gap · competitive analysis · personas · positioning · pricing | product, founder |
| [05-TARGET-ARCHITECTURE-AND-ROADMAP.md](05-TARGET-ARCHITECTURE-AND-ROADMAP.md) | NFR targets · target architecture · modularization · SDK strategy · build-vs-buy · risk register · roadmap · readiness gates | eng lead, founder |
| [06-PORTFOLIO-AUDIT-ERP.md](06-PORTFOLIO-AUDIT-ERP.md) | **erp-backend + erp-frontend audit** · verified scale · 8 architecture rules assessed · EB findings · frontend verdict · cross-repo consistency | an engineer or architect |
| [07-KNOWLEDGE-EXTRACTION.md](07-KNOWLEDGE-EXTRACTION.md) | **Hidden gems** · business rules inventory · pattern catalogue · 12 package candidates · reusability matrix · language-agnostic specs · product opportunities | architect, founder |
| [08-CLEANUP-PLAN.md](08-CLEANUP-PLAN.md) | The sequenced plan across all three repos · 5 tracks · what to delete · the blocking decision *(sequencing superseded by 09)* | architect, eng lead |
| [09-WORK-MAP.md](09-WORK-MAP.md) | **Verified current state** (closed vs open) · the verification blocker · 6 phases of concrete tasks with acceptance criteria · **this week's plan** · decisions needed | **everyone — start here for action** |

---

## The one-paragraph version

**Masaar** is a strong ZATCA compliance core wrapped in an unfinished platform shell — genuinely good domain logic (XML, ICV/PIH chaining, TLV QR, XAdES signing) and an excellent multi-jurisdiction `ComplianceEngine` abstraction, surrounded by four unauthenticated entry points, three licensing systems, two auth stacks, and eleven empty SDK directories its README advertises as shipped. **erp-backend** is the opposite and the surprise of the audit: SAP-parity domain depth across 25+ modules, eight architectural rules with honest enforcement markers, and production-grade primitives — held back by adopting its own best pattern in 1 of 25 places, and a tenant scope that silently disengages outside HTTP. **erp-frontend** is a design system with three placeholder apps: 38 application source files against 3,155 backend routes. **The largest opportunity is not new code — it is moving erp-backend's existing, production-tested primitives into Masaar**, which closes a Critical security finding by transplant rather than by writing anything new.

---

## Findings index

| ID | Severity | Finding | Document |
|---|:---:|---|---|
| C-1 | 🔴 Critical | Masaar `/admin/*` web console has no authentication | [02 §1](02-SECURITY-AUDIT.md#-c-1--the-admin-web-console-has-no-authentication) |
| C-2 | 🔴 Critical | Masaar `/portal/*` reads tenant from `?org_id=`, unauthenticated | [02 §1](02-SECURITY-AUDIT.md#-c-2--customer-portal-reads-tenant-identity-from-a-query-parameter-unauthenticated) |
| C-3 | 🔴 Critical | API key **and secret** accepted from query parameters | [02 §1](02-SECURITY-AUDIT.md#-c-3--api-key-and-secret-accepted-from-url-query-parameters) |
| C-4 | 🔴 Critical | `TenantIsolationGuard` is dead code; no isolation defence-in-depth | [02 §1](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) |
| H-1…H-6 | 🟠 High | Key management · SSRF · broken CRL check · shell-outs · config injection · open metrics | [02 §2](02-SECURITY-AUDIT.md#2-high-findings) |
| M-1…M-8 · L-1…L-4 | 🟡🔵 | XML hardening · dual authz · rate limiting · audit gaps · dependency scanning | [02 §3](02-SECURITY-AUDIT.md#3-medium-findings), [§4](02-SECURITY-AUDIT.md#4-low-findings) |
| **EB-H-1** | 🟠 High | erp-backend tenant scope silently disengages outside HTTP | [06 §4](06-PORTFOLIO-AUDIT-ERP.md#-eb-h-1--the-tenant-global-scope-silently-disengages-outside-http-requests) |
| **EB-H-2** | 🟠 High | 501 models carry `organization_id` without the tenancy trait | [06 §4](06-PORTFOLIO-AUDIT-ERP.md#-eb-h-2--501-models-carry-organization_id-without-the-tenancy-trait) |
| **FE-1** | 🔴 Blocking | erp-frontend has no decided purpose — 38 files vs 3,155 routes | [06 §5.2](06-PORTFOLIO-AUDIT-ERP.md#52-what-this-means-strategically), [08 §7](08-CLEANUP-PLAN.md#7-the-one-decision-that-must-be-made-by-a-human) |
| Q-1…Q-10 · P-1…P-8 · A-1…A-8 · R-1…R-16 | — | Code quality · performance · architecture recommendations · risk register | [03](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md), [01 §7](01-DISCOVERY-AND-ARCHITECTURE.md#7-summary-of-architectural-recommendations), [05 §6](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#6-risk-register) |
| G-1…G-7 · P1…P12 | 💎 | Hidden gems · package extraction candidates | [07 §2](07-KNOWLEDGE-EXTRACTION.md#2-hidden-gems), [§5](07-KNOWLEDGE-EXTRACTION.md#5-suggested-independent-packages) |

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

Both passes are static and partial. Read **[02 §8 — What this audit did not cover](02-SECURITY-AUDIT.md#8-what-this-audit-did-not-cover)** before treating any section as exhaustive.

Applying to pass 1: no dynamic testing, no dependency CVE scan, no infrastructure review, and no cryptographic conformance verification of the ZATCA signature construction against the official specification.

Applying to pass 2: `erp-backend` holds 426 services across 25+ domains; this pass sampled the architectural layer, the platform primitives, and representative services from Accounting, Tax, Sales, Manufacturing and Inventory. **The business-rule catalogues in [07 §3](07-KNOWLEDGE-EXTRACTION.md#3-business-knowledge-inventory) are structural findings and named inventories, not an exhaustive line-by-line rule extraction** — that requires domain-expert review per module and is scoped as item E-3 in the cleanup plan.

## Status

**Nothing in these documents has been implemented.** Both passes are analysis only, per the audit brief. Execution is authorised separately — see [08 §10](08-CLEANUP-PLAN.md#10-status-and-next-step).

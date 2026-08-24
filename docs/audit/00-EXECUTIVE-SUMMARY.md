# Masaar Platform Audit — Executive Summary

**Audit date:** 2026-08-03
**Scope:** `Masaar` (compliance platform), `erp-backend`, `erp-frontend`
**Method:** Read-only static investigation. No code was modified.
**Audience:** Founders, engineering leadership, prospective technical partners.

---

## 0. Where this stands now

The assessment below was written against the codebase as it was found. Much of
it has since been acted on, and one part of it was wrong.

**The correction first.** The verdict said the compliance core was the sound
part and the shell around it was not. The opposite was closer to the truth. The
core did not work:

- The XAdES signature was computed over the empty string. `sign()` canonicalised
  `SignedInfo` before attaching it to the document, and `DOMNode::C14N()` returns
  `""` for a detached subtree without complaining. Every invoice carried a
  well-formed signature that verified against nothing.
- The signature was also placed outside `UBLExtensions`, where no verifier looks
  for it, because the scaffold that holds it was never built.
- Certificate requests could not be generated at all — three separate defects,
  including an unchecked `openssl_pkey_export()` that returned an empty private
  key.
- Every tax subtotal declared a base that already included the tax it was meant
  to explain: 150 on a stated 1150 at 15%.
- A document discount reduced the total but not the tax, so the taxpayer
  overpaid and the document failed BR-CO-13.
- Foreign-currency invoices reported Saudi VAT in the foreign currency, under-
  reporting it by the exchange rate.
- Credit notes carried no reason, which BR-KSA-17 requires.

None of these were visible from reading the code, which is why the original
assessment missed them. They were visible from running it and checking the
result against the specification's own arithmetic. What the code looked like was
a poor guide to what it did.

**What has closed since.** All four Criticals and six of seven Highs; the
authentication surface (no JWT request carried a tenant at all, so ninety routes
ran unscoped); tenant isolation, now structural and fitness-tested; licensing
consolidated to one domain; the OpenAPI description generated from the route
table and drift-tested.

| Then | Now |
|---|---|
| 4 unauthenticated entry points | 0 — `RouteAuthPostureTest` fails the build on an unguarded route |
| 3 licensing systems | 1 — `app/Domains/Licensing/` |
| 44 classes in `Fatoora/Services/` | 23 |
| 29 test files | 113, running 704 tests |
| SDKs are empty directories | 5,864 lines across eleven; the typed surface is generated |
| Spec omits three-quarters of the API | Generated from the routes, drift-tested both ways |

**What is still open.** Per-tenant signing keys under a managed KMS (H-1's
remaining half), and the conformance questions that need ZATCA's published
fixtures rather than a guess — the encoding of the certificate digest, whether
the tatweel is stripped before hashing, and which invoice type carries the
nine-tag QR. The self-consistency half of conformance is done and tested; the
half that needs their samples is not.

The scorecard below is the original one. It has not been re-scored, because a
number would suggest more precision than re-reading deserves.

---

## 1. The verdict

> **Masaar is a strong compliance core wrapped in an unfinished platform shell.**
> It is **not suitable for production use today** — but the right response is **remediation and simplification, not a rewrite.**

The ZATCA/Fatoora domain logic — XML generation, ICV/PIH hash chaining, TLV QR encoding, XAdES signing, CSID onboarding — is genuinely good work. It is well-factored, uses DTOs and enums correctly, and reflects real understanding of the ZATCA Phase 2 specification. That is the hard part, and it is largely done.

What surrounds it is not production-grade. There are **four unauthenticated entry points** exposing cross-tenant data and platform control, **three parallel and partly redundant licensing systems**, **two competing authentication stacks**, and a `Fatoora/Services/` directory holding **44 service classes** for a product with **29 test files**. The SDKs advertised in the README are empty directories. The OpenAPI specification documents endpoints that have been deprecated and omits roughly three-quarters of the real API.

The gap is not capability. It is **finish**.

### Production readiness scorecard

| Dimension | Score | Assessment |
|---|:---:|---|
| Compliance domain correctness | 🟢 **8/10** | Strong ZATCA implementation; needs conformance test evidence |
| Security | 🔴 **3/10** | 4 Critical, 6 High findings — see [02-SECURITY-AUDIT](02-SECURITY-AUDIT.md) |
| Architecture | 🟡 **6/10** | Good domain boundaries; severe accidental complexity above them |
| Simplicity | 🔴 **3/10** | 3 licensing systems, 2 auth stacks, 2 API surfaces, 44 services |
| Testing | 🔴 **3/10** | 29 test files / 188 source files; zero auth or tenancy tests |
| Documentation | 🟡 **5/10** | Volume is high, accuracy is low — spec drift and overclaims |
| Developer Experience | 🔴 **2/10** | SDKs are stubs; no sandbox, no CLI, spec is wrong |
| Operability | 🟡 **5/10** | Metrics and Docker exist; signing keys block horizontal scaling |
| **Overall production readiness** | 🔴 **4/10** | **Not shippable to paying customers as-is** |

---

## 2. What must be fixed before anyone can sell this

These four issues are not "technical debt." Each one is a live path to a cross-tenant data breach or platform compromise. All are cheap to fix.

| # | Finding | Impact | Effort |
|---|---|---|:---:|
| **C-1** | `/admin/*` web console has **no authentication middleware**. It exposes every organization, invoice, certificate and log — and its POST routes execute an Artisan command and mutate the offline queue by ID. | Total platform compromise, unauthenticated | **~1 hour** |
| **C-2** | `/portal/*` customer portal has **no authentication** and reads the tenant from `?org_id=`. Anyone who can guess or enumerate a UUID reads that tenant's invoices, submissions and certificate status. | Arbitrary cross-tenant data disclosure | **~1 day** |
| **C-3** | API key **and secret** are accepted from URL query parameters. Credentials land in access logs, proxy logs, browser history and `Referer` headers. | Credential leakage at scale | **~1 hour** |
| **C-4** | `TenantIsolationGuard` — a 300-line service whose docblock reads *"CRITICAL: One tenant's invalid data must NEVER affect another tenant"* — **has zero call sites.** Isolation rests entirely on a hand-written `where('organization_id', …)` in each controller method. One omission in one future method is a silent leak. | No defence-in-depth on the platform's single most important invariant | **~3 days** |

**Combined effort to close all four: under two weeks.** This is the single highest-return work available anywhere in the codebase.

Full detail, evidence and remediation for every finding: **[02-SECURITY-AUDIT.md](02-SECURITY-AUDIT.md)**.

---

## 3. The three structural problems

Beyond security, three architectural decisions are actively taxing every future change.

### 3.1 Three licensing systems

`app/Domains/Licensing/` (customer API licences), `app/Services/Licensing/PlatformLicenseService` (Masaar's own licence to run), and `app/Models/LicenseRegistration` (self-service registration) are three separate concepts, three data models and three middleware paths — all called "license." Combined with a `platform.license` middleware and a `CheckPlatformLicense` command, a new engineer cannot answer "which licence gates this route?" without reading five files.

**Recommendation:** collapse to two clearly-named concepts — **Subscription** (what a customer bought) and **Deployment Licence** (Masaar's right-to-run, only relevant for on-prem). Delete the third. See [01-DISCOVERY-AND-ARCHITECTURE §5](01-DISCOVERY-AND-ARCHITECTURE.md#5-anti-patterns).

### 3.2 Two authentication stacks and two API surfaces

`/api/*` uses JWT with `TenantResolver`; `/api/v1/*` uses API key+secret with a `License` model. They have different scope vocabularies, different rate limiters, different error envelopes, and expose **the same compliance operations at different URLs** (`/api/compliance/sa/submit/{id}` vs `/api/v1/compliance/submit/{id}`). Every new endpoint must be built, tested and documented twice.

**Recommendation:** one surface — `/v1/*` — with two credential types resolving to one `AuthContext`. See [05-TARGET-ARCHITECTURE §2](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#2-target-architecture).

### 3.3 Speculative infrastructure built before demand

`Fatoora/Services/` contains 44 classes including `CircuitBreaker` **and** `ClusterCircuitBreaker`, `BackPressureManager`, `KillSwitchManager`, `HashChainAnomalyDetector`, `ArchivedTenantReconstructor`, `ComplianceSnapshot` and `QueueHealthMonitor` — resilience machinery for a system that has not yet served a production invoice, sitting beside a test suite that does not cover authentication.

This is YAGNI at scale. It is not free: it is surface area that must be read, maintained, secured and understood by every future contributor, and it crowds out the work that actually blocks revenue.

**Recommendation:** keep one circuit breaker, keep the offline queue (ZATCA genuinely requires it), and move the rest behind a documented "deferred" boundary or delete it. Detail and per-class disposition in [03-QUALITY-PERFORMANCE-MAINTAINABILITY §2](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#2-complexity-assessment).

---

## 4. The Developer Experience gap

The stated goal is a **world-class ZATCA Developer Experience platform**. Measured against that goal, this is the weakest area of the entire project — and, unlike security, it is a gap in *work not yet done* rather than work done wrongly.

| Claim in the README | Reality on disk |
|---|---|
| "SDKs available in `sdks/`: PHP, TypeScript, Python, Java, Go, Kotlin, Dart, Swift, Ruby, Rust, .NET" | **11 of 12 SDK directories contain 3 or fewer source files.** Most contain only a README. Java has 15 files; JavaScript has zero. |
| `docs/openapi.yaml` as the API contract | **16 documented paths** against ~60 real routes. Documents the **deprecated** `/compliance/zatca/` prefix that the API now answers with a 301. |
| "Production ready" (Saudi Arabia) | No sandbox environment, no CLI, no XML visualiser, no validation-error explanation engine, no published Postman collection. |

A developer integrating today must read Laravel source to discover the request shape. That is the opposite of the goal.

**Recommendation:** make the OpenAPI spec the *generated, tested* single source of truth, then generate SDKs from it rather than hand-writing eleven. One real SDK (TypeScript) plus a correct spec beats eleven stubs. Full plan: **[04-PRODUCT-DX-AND-GAP-ANALYSIS.md](04-PRODUCT-DX-AND-GAP-ANALYSIS.md)**.

---

## 5. Repository relationship and duplication

The three repositories relate as follows:

```
┌──────────────────┐   HTTP/REST    ┌──────────────────┐   HTTPS   ┌─────────────┐
│  erp-frontend    │──────────────▶ │   erp-backend    │──────────▶│   Masaar    │
│  (React monorepo)│                │  (Laravel ERP)   │  /pipeline│  (platform) │
│  staff/admin/    │                │  2,100 files     │  /submit  │  188 files  │
│  portal          │                │                  │           │             │
└──────────────────┘                └──────────────────┘           └──────┬──────┘
                                             │                            │
                                             │                            ▼
                                    ⚠ ALSO contains its own       ┌──────────────┐
                                      ZatcaClientV1 +             │ ZATCA / FTA  │
                                      ZatcaInvoiceTransformer     │ authorities  │
                                      (duplicate logic)           └──────────────┘
```

`erp-backend` reaches Masaar through `CompliPayClient` → `POST /pipeline/submit`, which is the correct seam. **But it also carries its own `ZatcaClientV1` and `ZatcaInvoiceTransformer`** — a second, thinner implementation of the same transformation. Two implementations of a regulated mapping will drift, and the drift will surface as rejected invoices.

**Recommendation:** `erp-backend` must own *no* ZATCA knowledge beyond building the Masaar request payload. Delete `ZatcaClientV1`; keep `CompliPayClient`. This also makes Masaar independently sellable, which is the entire commercial thesis.

---

## 6. What to do, in order

Complexity: **S** ≤1 week · **M** 1–4 weeks · **L** 1–3 months · **XL** 3 months+

### Phase 0 — Stop the bleeding (2 weeks, blocking)
| Action | Complexity |
|---|:---:|
| Fix C-1 … C-4 (see §2) | **S** |
| Add feature tests that *prove* every route's auth and tenancy | **S** |
| Remove SSRF and the broken CRL serial comparison (H-2, H-3) | **S** |

*Nothing else ships until this phase is green.*

### Phase 1 — Make it honest (4 weeks)
| Action | Complexity |
|---|:---:|
| Regenerate OpenAPI from routes; add a CI test that fails on drift | **M** |
| Correct README/docs overclaims (SDKs, "production ready") | **S** |
| Collapse 3 licensing systems → 2; unify the two auth stacks | **M** |
| Raise coverage on auth, tenancy, licensing, signing to ≥80% | **M** |

### Phase 2 — Make it a platform (8 weeks)
| Action | Complexity |
|---|:---:|
| One API surface (`/v1`), one error envelope, one scope vocabulary | **M** |
| Move signing keys to KMS/Vault with envelope encryption (H-1) | **M** |
| Public sandbox with seeded test tenants and ZATCA simulation | **M** |
| Generated TypeScript + PHP SDKs from the spec | **M** |

### Phase 3 — Make it world-class DX (12 weeks)
| Action | Complexity |
|---|:---:|
| Validation-error explanation engine (ZATCA code → plain-English fix) | **M** |
| Invoice debugger + XML visualiser in the developer dashboard | **L** |
| CLI (`masaar validate invoice.xml`) | **M** |
| Remaining SDKs, generated | **M** |

Full roadmap with dependencies, risk register and NFR targets: **[05-TARGET-ARCHITECTURE-AND-ROADMAP.md](05-TARGET-ARCHITECTURE-AND-ROADMAP.md)**.

---

## 7. The strategic opportunity

The GCC e-invoicing market has a genuine gap. Incumbent ZATCA vendors sell **compliance to finance departments**. Nobody is selling **compliance as a developer product** — with a real sandbox, honest SDKs, an invoice debugger and error messages that explain themselves.

Masaar is unusually well-positioned for that, for one specific reason: the `ComplianceEngine` / `ComplianceRouter` abstraction is a genuinely good multi-jurisdiction design. Adding UAE FTA required a new engine, not a fork. **"One API, every GCC jurisdiction"** is a defensible position that a KSA-only incumbent cannot match without rebuilding — and the 2027 UAE mandate is a hard deadline that creates the buying window.

That opportunity is real. It is currently gated by an unauthenticated admin console and eleven empty SDK folders — both of which are weeks of work, not quarters.

---

## Document index

| Document | Covers |
|---|---|
| **00-EXECUTIVE-SUMMARY.md** *(this file)* | Verdict, top risks, roadmap at a glance |
| [01-DISCOVERY-AND-ARCHITECTURE.md](01-DISCOVERY-AND-ARCHITECTURE.md) | System map, request lifecycle, invoice lifecycle, architecture assessment, anti-patterns |
| [02-SECURITY-AUDIT.md](02-SECURITY-AUDIT.md) | Full security audit, every finding with evidence, severity and remediation |
| [03-QUALITY-PERFORMANCE-MAINTAINABILITY.md](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md) | Code quality, complexity, performance, maintainability, consistency standard, testing strategy |
| [04-PRODUCT-DX-AND-GAP-ANALYSIS.md](04-PRODUCT-DX-AND-GAP-ANALYSIS.md) | Documentation audit, DX assessment, feature gap analysis, competitive analysis, product strategy |
| [05-TARGET-ARCHITECTURE-AND-ROADMAP.md](05-TARGET-ARCHITECTURE-AND-ROADMAP.md) | NFRs, target architecture, modularization, compatibility, build-vs-buy, risk register, roadmap, backlog |

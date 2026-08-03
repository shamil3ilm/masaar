# Non-Functional Requirements, Target Architecture, Modularization, Compatibility, Build-vs-Buy, Risk Register & Roadmap

**Audit date:** 2026-08-03
**Related:** [00-EXECUTIVE-SUMMARY](00-EXECUTIVE-SUMMARY.md) · [01-DISCOVERY-AND-ARCHITECTURE](01-DISCOVERY-AND-ARCHITECTURE.md) · [02-SECURITY-AUDIT](02-SECURITY-AUDIT.md)

---

## 1. Non-functional requirements

Currently no NFR is stated, measured or alerted on anywhere in the codebase. These are proposed targets. **Adopt them, instrument them, and publish the ones customers depend on.**

| Attribute | Target | Measurement | Current |
|---|---|---|---|
| **Availability** | 99.9% monthly (43 min downtime) for submission APIs | Synthetic probe every 30s from 2 regions | Unknown — single replica, blocked by [H-1](02-SECURITY-AUDIT.md#-h-1--signing-keys-are-encrypted-with-app_key-on-a-local-filesystem-disk) |
| **Latency — sync API** | p95 < 300 ms, p99 < 800 ms (excluding ZATCA round-trip) | Per-route histogram | Unmeasured |
| **Latency — signing** | p95 < 200 ms | Pipeline-stage timing | Unmeasured; [P-2](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#3-performance-review), [P-3](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#3-performance-review) are risks |
| **Latency — end-to-end submission** | p95 < 5 s including ZATCA | Trace span | Unmeasured |
| **Throughput** | 100 invoices/sec sustained; 500/sec burst absorbed by queue | Load test | Untested |
| **Scalability** | Stateless horizontal scale, ≥8 app replicas | Load test at replica count | **Not achievable** until H-1 is fixed |
| **Durability** | Zero accepted-then-lost invoices | Reconciliation job: accepted vs. submitted vs. acknowledged | Unmeasured |
| **Recovery — RPO** | ≤ 5 min | Backup + binlog | Undefined |
| **Recovery — RTO** | ≤ 1 hour | Documented, **rehearsed** restore | Undefined, never tested |
| **Data retention** | 6 years (ZATCA statutory) | Archive verification job | Undefined |
| **Security** | 0 Critical, 0 High open findings | Quarterly audit + CI scanning | **4 Critical, 6 High** |
| **Maintainability** | ≥80% coverage; PHPStan level 6+; 0 files >800 lines | CI gates | ~15% coverage; no static analysis in CI |
| **Observability** | 100% of requests carry a trace ID; every submission stage emits a span | Trace completeness check | Request IDs exist; no distributed tracing |
| **Compatibility** | Any language with an HTTP client; no SDK required | Contract tests | Achievable; spec is wrong |

**Highest priority:** define RPO/RTO and **rehearse a restore**. An untested backup is a hypothesis. For a system holding six years of statutory tax records, that is the single largest unquantified operational risk.

---

## 2. Target architecture

### 2.1 Delivery model — recommendation

**A modular monolith. One deployable Laravel application with enforced internal module boundaries.**

Rejected alternatives and why:

| Model | Verdict |
|---|---|
| **Microservices** | ❌ For a team of this size with a pre-production product, distributed transactions across signing, hash-chaining and submission would add far more failure modes than they remove. The ICV/PIH chain is inherently stateful and ordered — splitting it across services is actively harmful. |
| **Separate services per jurisdiction** | ❌ The `ComplianceEngine` abstraction already provides the isolation. Deployment separation adds operational cost with no benefit. |
| **Current monolith, unchanged** | ❌ Module boundaries exist by convention only; nothing prevents cross-domain coupling. |
| **Modular monolith** | ✅ **Recommended** — keeps deployment simple, makes boundaries enforceable in CI, and preserves the option to extract a service later if load genuinely demands it. |

### 2.2 Target shape

```
┌──────────────────────────────────────────────────────────────────┐
│                        EDGE (Traefik)                            │
│   TLS · rate limiting · IP allowlist for /admin and /metrics     │
└────────────────────────────┬─────────────────────────────────────┘
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│                  ONE API SURFACE  —  /v1/*                       │
│                                                                  │
│   Authenticate ──▶ AuthContext { tenant, actor, credential,      │
│                                  scopes[], environment }         │
│      ├─ JWT credential      (interactive / dashboard)            │
│      └─ API key credential  (server-to-server)                   │
│                                                                  │
│   Authorize (ApiScope) ─▶ Quota ─▶ RateLimit(tenant, route-cost) │
└────────────────────────────┬─────────────────────────────────────┘
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│                    APPLICATION LAYER                             │
│   Thin controllers · FormRequests · one response envelope        │
└────────────────────────────┬─────────────────────────────────────┘
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│                   DOMAIN MODULES (enforced boundaries)           │
│                                                                  │
│  Identity      Organization     Invoice       Compliance         │
│  ─ users       ─ orgs/branches  ─ invoices    ─ ComplianceRouter │
│  ─ api keys    ─ profiles       ─ lines       ─ SA: Fatoora      │
│  ─ AuthContext ─ BelongsToTenant               ─ AE: FTA         │
│                                                ─ QA: (planned)   │
│  Subscription  Webhook          Audit                            │
│  ─ plan/quota  ─ delivery       ─ security + data events         │
│  ─ metering    ─ signing/retry                                   │
└────────────────────────────┬─────────────────────────────────────┘
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│                     INFRASTRUCTURE                               │
│   MySQL · Redis (queue+cache) · S3 (artefacts) · KMS (keys)      │
│   OpenTelemetry ──▶ traces/metrics/logs                          │
└──────────────────────────────────────────────────────────────────┘
```

### 2.3 Key changes from current

| # | Change | Replaces | Why |
|---|---|---|---|
| 1 | **One `AuthContext`** | `TenantResolver` singleton + `$request->attributes` | [01 §5.5](01-DISCOVERY-AND-ARCHITECTURE.md#55-anti-pattern-two-contexts-for-one-fact) |
| 2 | **One `/v1/*` surface**, two credential types | `/api/*` + `/api/v1/*` | Halves the cost of every endpoint |
| 3 | **`BelongsToTenant` global scope** | `TenantIsolationGuard` (dead) + manual `where()` | [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) |
| 4 | **`Subscription` + `DeploymentLicence`** | three licensing systems | [01 §5.2](01-DISCOVERY-AND-ARCHITECTURE.md#52-anti-pattern-three-licensing-systems) |
| 5 | **KMS envelope encryption, keys in DB** | `encrypt()` + local disk | [H-1](02-SECURITY-AUDIT.md#-h-1--signing-keys-are-encrypted-with-app_key-on-a-local-filesystem-disk); unblocks horizontal scale |
| 6 | **Generated OpenAPI + generated SDKs** | hand-written spec, 11 stubs | [04 §1](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#1-documentation-audit) |
| 7 | **Sub-namespaced Fatoora domain** | 44 flat classes | [03 §2.3](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#23-re-namespacing) |
| 8 | **Module boundary enforcement in CI** | convention only | Prevents re-coupling |

### 2.4 Boundary enforcement

Add [`deptrac`](https://github.com/qossmic/deptrac) or a PHPStan rule set to CI with these layers:

```
Http  →  Application  →  Domain  →  (nothing)
                            ↑
                    Infrastructure implements Domain contracts
```

Rules: no Domain class may reference `Illuminate\Http`; no cross-domain reference except through published contracts; `Compliance` may not reference `Subscription`. Failing the build on violation is what makes "modular" real rather than aspirational.

---

## 3. Modularization strategy

**Recommendation: one platform, several delivery artefacts.**

| Artefact | Contents | Distribution | Priority |
|---|---|---|:---:|
| **Compliance Platform** | The Laravel modular monolith | Docker image; SaaS + on-prem | ✅ Exists |
| **Developer Portal** | Signup, keys, usage, logs, invoice debugger | SPA against `/v1` | 🔴 Missing — **build after MVP security** |
| **Admin Console** | Internal platform operations | SPA or hardened Blade | 🟡 Exists, unauthenticated ([C-1](02-SECURITY-AUDIT.md#-c-1--the-admin-web-console-has-no-authentication)) |
| **SDKs** | Thin generated HTTP clients | Package registries | 🔴 Stubs |
| **CLI** | `masaar validate\|sign\|submit\|onboard` | Single binary / npm | 🔴 Missing |
| **OpenAPI spec** | The contract | Published, versioned | 🔴 Wrong |
| **ERP connector** | `CompliPayClient` extracted as a Composer package | Packagist | 🟡 Embedded in `erp-backend` |

**What must not be extracted.** The compliance engines, signing, hash chain and offline queue stay inside the platform. They share transactional state and strict ordering; distributing them would trade a solvable complexity problem for an unsolvable consistency one.

**Composer package boundary.** Extract `CompliPayClient` from `erp-backend` into `masaar/php-sdk` — this simultaneously deletes the duplicated ZATCA logic ([01 §1.1](01-DISCOVERY-AND-ARCHITECTURE.md#11-the-duplication-problem)), produces the first real SDK, and forces the API contract to be honest because the SDK is generated from it. **This is a high-leverage move: one piece of work resolves three findings.**

---

## 4. Compatibility strategy

**Principle: the HTTP API is the product. SDKs are conveniences, never requirements.**

Any language listed in the brief — PHP, JavaScript, TypeScript, Python, Java, C#, Go, Rust, Kotlin, Swift — and any framework — Laravel, Symfony, ASP.NET, Spring Boot, Express, NestJS, Django, FastAPI, Flask, Rails, Next.js, Nuxt, React, Vue, Angular — integrates through the same four mechanisms:

| Mechanism | Requirement |
|---|---|
| **REST over HTTPS, JSON** | No language-specific serialisation. No XML in the request path — Masaar generates UBL; the customer never touches it. |
| **Header authentication** | `X-API-Key` / `X-API-Secret`, or `Authorization: Bearer`. Never query parameters ([C-3](02-SECURITY-AUDIT.md#-c-3--api-key-and-secret-accepted-from-url-query-parameters)). |
| **Webhooks with HMAC-SHA256** | Documented signature scheme, timestamp, replay window, retry-with-backoff, and a delivery log the customer can inspect. |
| **OpenAPI 3.1** | The single source of truth. Every SDK generated from it. |

### 4.1 SDK strategy

**Generate, do not hand-write.** Eleven hand-maintained SDKs is eleven repositories drifting from the spec at different rates — the failure is already visible in the current stubs.

| Tier | Languages | Approach |
|---|---|---|
| **Tier 1 — maintained, tested** | TypeScript, PHP | Generated, then hand-polished for idiomatic ergonomics. CI-tested against the live sandbox. |
| **Tier 2 — generated, published** | Python, Java, C#, Go | Pure generation from the spec. Regenerated on every spec release. |
| **Tier 3 — spec only** | Rust, Kotlin, Swift, Dart, Ruby | Publish the OpenAPI spec and document `openapi-generator` usage. **Delete the empty directories** — an empty folder is a broken promise. |

Sequence: fix the spec → generate Tier 1 → prove the workflow → generate Tier 2 → remove Tier 3 stubs and document self-generation.

### 4.2 Framework-level integration

Ship recipes rather than framework-specific packages: Laravel (queued job + event listener), NestJS (injectable module), Django (signal handler), Spring Boot (`@Service` bean). Each is ~50 lines of documentation using the Tier 1/2 SDK. This is far cheaper than maintaining framework packages and covers the long tail.

---

## 5. Build vs Buy

Every hour spent on undifferentiated infrastructure is an hour not spent on the compliance engine or the developer experience.

| Component | Verdict | Rationale |
|---|---|---|
| **ZATCA / FTA compliance engine** | 🟢 **BUILD** | This *is* the product. Already built. |
| **Multi-jurisdiction routing** | 🟢 **BUILD** | The core differentiator. |
| **Developer experience (sandbox, debugger, explanation engine)** | 🟢 **BUILD** | The second differentiator; no vendor sells this for GCC. |
| **Authentication (JWT/API keys)** | 🟡 **KEEP** what exists, do not extend | Working. Not worth an Auth0 dependency for API-key auth. Revisit if SSO/SAML becomes an enterprise requirement. |
| **Key management** | 🔴 **BUY** | AWS KMS or HashiCorp Vault. Never hand-roll key management for non-repudiation signing keys ([H-1](02-SECURITY-AUDIT.md#-h-1--signing-keys-are-encrypted-with-app_key-on-a-local-filesystem-disk)). |
| **Billing & subscriptions** | 🔴 **BUY** | Stripe Billing / Paddle. Metering stays in-platform; invoicing, dunning, tax and payment methods do not. |
| **Monitoring & APM** | 🔴 **BUY** | Grafana Cloud, Datadog or self-hosted Prometheus+Grafana (partly configured already). Delete `QueueHealthMonitor` and `BackPressureManager` in favour of it. |
| **Error tracking** | 🔴 **BUY** | Sentry. |
| **Log aggregation** | 🔴 **BUY** | Loki / CloudWatch. `OrganizationLogHandler` writing per-tenant files does not scale and complicates retention. |
| **Status page** | 🔴 **BUY** | Statuspage / Instatus. Table stakes for an API product. |
| **API documentation site** | 🔴 **BUY** | Mintlify / ReadMe / Scalar, driven by the OpenAPI spec. Do not build a docs platform. |
| **Email delivery** | 🔴 **BUY** | Postmark / SES. |
| **Notifications** | 🟡 **BUILD thin** | Webhooks are built; email via the provider above. |
| **Search** | ⚪ **DEFER** | Not needed at current scale. |
| **Circuit breaking / back-pressure** | 🟡 **SIMPLIFY** | Keep one simple circuit breaker; delegate the rest to the queue and the APM ([01 §5.3](01-DISCOVERY-AND-ARCHITECTURE.md#53-anti-pattern-speculative-resilience-infrastructure)). |

**Estimated saving from buying the above: 3–6 engineer-months**, redirected to the sandbox, SDKs and the UAE engine.

---

## 6. Risk register

| ID | Risk | Category | Likelihood | Impact | Score | Mitigation | Owner |
|---|---|---|:---:|:---:|:---:|---|---|
| **R-1** | Unauthenticated `/admin` or `/portal` reached in a deployed environment → cross-tenant tax-data breach, PDPL exposure | Security | **High** | **Critical** | 🔴 **9** | [C-1](02-SECURITY-AUDIT.md#-c-1--the-admin-web-console-has-no-authentication)/[C-2](02-SECURITY-AUDIT.md#-c-2--customer-portal-reads-tenant-identity-from-a-query-parameter-unauthenticated) in Sprint 1; route-posture tests; edge IP allowlist | Eng lead |
| **R-2** | Cross-tenant leak from one omitted `where()` clause | Security | Medium | **Critical** | 🔴 **8** | [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) global scope + isolation test suite | Eng lead |
| **R-3** | `APP_KEY` compromise exposes **every** tenant's signing key | Security | Low | **Critical** | 🔴 **7** | [H-1](02-SECURITY-AUDIT.md#-h-1--signing-keys-are-encrypted-with-app_key-on-a-local-filesystem-disk) KMS envelope encryption; rotation command | Eng lead |
| **R-4** | Invoices signed with a revoked certificate → ZATCA penalties, invalid tax documents | Compliance | Medium | High | 🟠 **6** | [H-3](02-SECURITY-AUDIT.md#-h-3--crl-revocation-check-is-silently-non-functional) fix + revoked-cert fixture test | Eng lead |
| **R-5** | XML/signature non-conformance discovered only in production | Compliance | Medium | **Critical** | 🔴 **8** | ZATCA conformance suite against official fixtures ([03 §6](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#6-testing-strategy)); simulation-environment soak | Eng lead |
| **R-6** | No ZATCA solution-provider accreditation → cannot sell to production customers | Compliance/Business | Medium | **Critical** | 🔴 **8** | Start accreditation **now**; months of lead time | Founder |
| **R-7** | Backups never restore-tested; 6 years of statutory records at risk | Operational | Medium | **Critical** | 🔴 **8** | Define RPO/RTO; **rehearse** a restore quarterly | Ops |
| **R-8** | Single replica cannot scale; H-1 blocks horizontal scaling | Operational | Medium | High | 🟠 **6** | Fix H-1; load test at 8 replicas | Eng |
| **R-9** | ZATCA revises the specification; platform falls out of compliance | Compliance | **High** | High | 🟠 **7** | Assign a named spec owner; subscribe to ZATCA bulletins; version-tag rules (`rule_version` column already exists) | Compliance lead |
| **R-10** | Duplicate ZATCA logic in `erp-backend` drifts → rejected invoices | Technical | **High** | Medium | 🟠 **6** | Delete `ZatcaClientV1`; single source of truth | Eng |
| **R-11** | Wrong OpenAPI spec causes failed integrations and reputational damage | Business | **High** | Medium | 🟠 **6** | Generate spec; CI drift test | Eng |
| **R-12** | Empty SDK directories damage credibility with the primary persona | Business | **High** | Medium | 🟠 **6** | Remove stubs; ship one real SDK | Product |
| **R-13** | Accidental complexity (3 licensing systems, 2 auth stacks) slows every future change | Technical | **High** | Medium | 🟠 **6** | Consolidation in Phase 1 | Eng lead |
| **R-14** | Key-person dependency on ZATCA domain knowledge | Business | Medium | High | 🟠 **6** | Document domain rules; pair on the compliance engine | Founder |
| **R-15** | Competitor ships GCC developer platform first | Business | Medium | High | 🟠 **6** | Prioritise sandbox + SDK; lead on the UAE 2027 mandate | Product |
| **R-16** | Dependency CVE in an unpatched package (lock file 6 months stale) | Security | Medium | Medium | 🟡 **4** | `composer audit` + Renovate in CI ([L-3](02-SECURITY-AUDIT.md#4-low-findings)) | Eng |

**Score = likelihood × impact, 1–9.** Anything ≥7 is unacceptable for a production launch.

---

## 7. Roadmap

Complexity: **S** ≤1 week · **M** 1–4 weeks · **L** 1–3 months · **XL** 3 months+

### Phase 0 — Stop the bleeding *(2 weeks — blocking, nothing ships in parallel)*

| # | Work | Complexity | Depends on | Closes |
|---|---|:---:|---|---|
| 0.1 | Authenticate `/admin`; move route closures into the controller | **S** | — | C-1 |
| 0.2 | Authenticate `/portal`; derive tenant from session | **S** | — | C-2 |
| 0.3 | Remove query-parameter credentials; rotate exposed keys | **S** | — | C-3 |
| 0.4 | Restrict `/metrics`; fix `.env.example`; add production/debug boot assertion | **S** | — | H-6, M-6 |
| 0.5 | Route-posture test for every registered route | **S** | 0.1–0.4 | R-1 |
| 0.6 | Fix CRL serial comparison + revoked-certificate fixture test | **S** | — | H-3, R-4 |
| 0.7 | Allowlist OCSP/CRL endpoints; harden the fetch client | **S** | — | H-2 |

**Exit criterion:** zero Critical findings; route-posture test green in CI.

### Phase 1 — Make it honest *(4 weeks)*

| # | Work | Complexity | Depends on |
|---|---|:---:|---|
| 1.1 | Generate OpenAPI from routes/FormRequests; CI drift test | **M** | — |
| 1.2 | Correct README and docs; delete SDK stubs; withdraw "production ready" | **S** | — |
| 1.3 | `AuthContext`; unify the two auth stacks | **M** | 0.x |
| 1.4 | `BelongsToTenant` global scope; delete `TenantIsolationGuard`; isolation test suite | **M** | 1.3 |
| 1.5 | Convert web controllers from `DB::table()` to Eloquent so the scope applies | **S** | 1.4 |
| 1.6 | Coverage to ≥80% on auth, tenancy, licensing, certificates | **M** | 1.3, 1.4 |
| 1.7 | ZATCA conformance suite against official fixtures | **M** | — |
| 1.8 | PHPStan level 6 + `deptrac` boundaries in CI; `composer audit` + Renovate | **S** | — |

**Exit criterion:** spec matches reality; C-4 closed; conformance suite green.

### Phase 2 — Make it a platform *(8 weeks)*

| # | Work | Complexity | Depends on |
|---|---|:---:|---|
| 2.1 | Collapse to one `/v1` surface, one error envelope, one scope vocabulary | **M** | 1.3 |
| 2.2 | Three licensing systems → `Subscription` + `DeploymentLicence` | **M** | 2.1 |
| 2.3 | KMS envelope encryption; keys off local disk; rotation command | **M** | — |
| 2.4 | Retire speculative services; re-namespace `Fatoora/` | **S** | 1.6 |
| 2.5 | **Public sandbox** — seeded tenants, test certificates, ZATCA simulator | **M** | 2.1 |
| 2.6 | Generated TypeScript + PHP SDKs; extract `CompliPayClient` → `masaar/php-sdk` | **M** | 1.1 |
| 2.7 | Delete `ZatcaClientV1` from `erp-backend` | **S** | 2.6 |
| 2.8 | Security audit events; OpenTelemetry tracing; buy Sentry + status page | **M** | — |
| 2.9 | Define + **rehearse** backup restore; document RPO/RTO | **S** | — |
| 2.10 | Load test at 8 replicas | **S** | 2.3 |

**Exit criterion:** a developer can sign up, hit the sandbox and submit a test invoice in under 10 minutes, with no sales contact.

### Phase 3 — Make it world-class DX *(12 weeks)*

| # | Work | Complexity | Depends on |
|---|---|:---:|---|
| 3.1 | Validation-explanation engine — ZATCA code → plain-English fix | **M** | 1.7 |
| 3.2 | Developer dashboard — keys, usage, logs, webhook deliveries | **L** | 2.1 |
| 3.3 | Invoice debugger — per-invoice pipeline trace with XML at each stage | **L** | 3.2 |
| 3.4 | XML visualiser | **M** | 3.3 |
| 3.5 | CLI — `masaar validate\|sign\|submit\|onboard` | **M** | 2.6 |
| 3.6 | Tier 2 SDKs generated (Python, Java, C#, Go) | **M** | 2.6 |
| 3.7 | Complete UAE FTA engine to production parity | **L** | 1.7 |
| 3.8 | Self-service signup + billing (Stripe) | **M** | 2.2 |

### Phase 4 — Scale *(ongoing)*

Qatar GTA engine · SOC 2 Type II · Peppol access point · ERP marketplace connectors · plugin architecture for custom validation rules · Oman/Bahrain/Kuwait.

### Dependency graph (critical path)

```
0.1─0.4 ──▶ 0.5 ──▶ 1.3 ──▶ 1.4 ──▶ 2.1 ──▶ 2.5 ──▶ 3.2 ──▶ 3.3
                      │                 │        │
              1.1 ────┴────▶ 2.6 ───────┘        └──▶ 3.5, 3.6
              1.7 ──▶ 3.1                    2.2 ──▶ 3.8
              2.3 ──▶ 2.10
```

**Longest path: 0.1 → 0.5 → 1.3 → 1.4 → 2.1 → 2.5 → 3.2 → 3.3.** Anything that shortens `1.3` (auth unification) shortens everything downstream — it is the highest-leverage single item on the roadmap after the Critical fixes.

---

## 8. Prioritized backlog

| # | Item | Value | Complexity | Priority |
|---|---|:---:|:---:|:---:|
| 1 | Authenticate `/admin` and `/portal` | 🔴 Critical | S | **P0** |
| 2 | Remove query-parameter credentials | 🔴 Critical | S | **P0** |
| 3 | Route-posture test suite | 🔴 Critical | S | **P0** |
| 4 | Fix CRL serial comparison | 🔴 Critical | S | **P0** |
| 5 | Restrict `/metrics`; fix `.env.example` | 🟠 High | S | **P0** |
| 6 | `BelongsToTenant` global scope + isolation tests | 🔴 Critical | M | **P0** |
| 7 | Generate accurate OpenAPI + CI drift test | 🔴 Critical | M | **P1** |
| 8 | Correct README; delete SDK stubs | 🟠 High | S | **P1** |
| 9 | `AuthContext`; unify auth stacks | 🟠 High | M | **P1** |
| 10 | ZATCA conformance test suite | 🔴 Critical | M | **P1** |
| 11 | KMS envelope encryption | 🟠 High | M | **P1** |
| 12 | Public sandbox | 🔴 Critical *(for revenue)* | M | **P1** |
| 13 | One `/v1` surface, one envelope | 🟠 High | M | **P2** |
| 14 | Consolidate licensing systems | 🟠 High | M | **P2** |
| 15 | TypeScript + PHP SDKs generated | 🟠 High | M | **P2** |
| 16 | Delete duplicate ZATCA logic in `erp-backend` | 🟠 High | S | **P2** |
| 17 | Retire speculative services; re-namespace | 🟡 Medium | S | **P2** |
| 18 | Rehearse backup restore; document RPO/RTO | 🟠 High | S | **P2** |
| 19 | Validation-explanation engine | 🟠 High | M | **P3** |
| 20 | Developer dashboard | 🟠 High | L | **P3** |
| 21 | Invoice debugger + XML visualiser | 🟡 Medium | L | **P3** |
| 22 | CLI | 🟡 Medium | M | **P3** |
| 23 | Complete UAE FTA engine | 🟠 High | L | **P3** |
| 24 | Self-service billing | 🟡 Medium | M | **P3** |

---

## 9. Production readiness assessment

**Verdict: 🔴 NOT PRODUCTION READY.**

| Gate | Status | Blocker |
|---|:---:|---|
| No Critical security findings | 🔴 | 4 open |
| No High security findings | 🔴 | 6 open |
| Tenant isolation structurally enforced | 🔴 | C-4 |
| Signing keys under managed KMS | 🔴 | H-1 |
| Compliance conformance evidence | 🔴 | No conformance suite |
| ≥80% test coverage on critical paths | 🔴 | ~15% |
| Accurate API documentation | 🔴 | Spec drift |
| Backup + restore rehearsed | 🔴 | Never tested |
| NFRs defined and measured | 🔴 | Undefined |
| Horizontal scalability proven | 🔴 | Blocked by H-1 |
| Monitoring and alerting | 🟡 | Metrics exist, unauthenticated, no alerts |
| Incident response runbook | 🔴 | Missing |
| ZATCA accreditation | ❓ | Status unknown — **confirm urgently** |

**Estimated time to production readiness: 3–4 months** at current team size, following the roadmap above — assuming Phase 0 and Phase 1 are executed without parallel feature work.

**What would make this shippable fastest:** Phase 0 (2 weeks) plus items 6, 7, 10 and 12 from the backlog. That is roughly 8 weeks to a defensible, honestly-documented, self-service-evaluable product with a tested compliance claim. Everything else can follow real customers.

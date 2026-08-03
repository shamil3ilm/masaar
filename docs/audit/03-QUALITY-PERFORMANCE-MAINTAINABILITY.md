# Code Quality, Complexity, Performance, Maintainability, Consistency & Testing

**Audit date:** 2026-08-03
**Related:** [00-EXECUTIVE-SUMMARY](00-EXECUTIVE-SUMMARY.md) · [01-DISCOVERY-AND-ARCHITECTURE](01-DISCOVERY-AND-ARCHITECTURE.md) · [02-SECURITY-AUDIT](02-SECURITY-AUDIT.md)

---

## 1. Code quality

### 1.1 What is done well

- **`declare(strict_types=1)`** applied consistently across the domain layer.
- **PHP 8 idioms used properly** — readonly promoted constructor properties, backed enums (`InvoiceStatus`, `TaxCategory`, `ApiScope`, `LicenseTier`), first-class DTOs, `match` where appropriate.
- **Naming is genuinely good.** `AtomicIcvManager`, `HashChainManager`, `EnvironmentVarianceTracker`, `SubmissionIdempotency` — a domain expert can read the file list and understand the system. This is rarer than it should be.
- **Comments explain *why*, not *what*.** `config/fatoora.php`'s idempotency scope declaration and `config/cors.php`'s explanation of the deliberate empty-origin default are exemplary.
- **Monetary arithmetic uses `bcmath`** throughout `InvoiceController::store()`. Floating-point tax computation is a leading cause of ZATCA rejection elsewhere; avoiding it is a real correctness win.
- **Thin controllers.** `ComplianceController` is a genuine pass-through to `FatooraSubmissionService`.

### 1.2 Defects and weaknesses

| ID | Issue | Evidence | Fix |
|---|---|---|---|
| **Q-1** | **Dead code presenting as a security control.** `TenantIsolationGuard`, 300 lines, zero callers. Two latent bugs inside it (`property_exists()` on Eloquent attributes never matches; `runWithoutTenant()` mutates singleton state) prove it was never exercised. | [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) | Delete; replace with a global scope. |
| **Q-2** | **Security decisions made by regex over CLI output.** `stripos($output, 'revoked')` / `stripos($output, 'good')` on `openssl ocsp` text. Any OCSP error message containing either word flips the verdict. | `CertificateService::checkOcsp()` | [H-4](02-SECURITY-AUDIT.md#-h-4--revocation-and-csr-paths-shell-out-to-external-binaries) — use `phpseclib`. |
| **Q-3** | **Integer overflow in a security comparison.** `dechex((int) $serialNumber)` on a 160-bit X.509 serial. | `CertificateService::checkCrl()` | [H-3](02-SECURITY-AUDIT.md#-h-3--crl-revocation-check-is-silently-non-functional). |
| **Q-4** | **Business logic in route closures.** `routes/web.php` contains an `Artisan::call()` and a raw `DB::table()->update()` inside route definitions — untestable, unauditable, and unauthenticated. | `routes/web.php:26-42` | Move into `AdminController`; cover with tests. |
| **Q-5** | **Raw `DB::table()` in web controllers** bypasses Eloquent, model events, casts and any future global scope. `CustomerPortalController` and `AdminController` are entirely query-builder based. | Both `Http/Controllers/Web/*` | Use Eloquent models so the `BelongsToTenant` scope applies. This is a prerequisite for C-4's fix to actually protect these paths. |
| **Q-6** | **Facade and `config()` calls inside domain services.** `CertificateService` calls `\Log::warning()` and `config()` directly, coupling the domain to the framework and impeding isolated testing. | `CertificateService.php` | Inject `LoggerInterface` and a typed config object (`FatooraConfig` already exists — use it consistently). |
| **Q-7** | **Duplicated implementations.** `CircuitBreaker` + `ClusterCircuitBreaker`; `FatooraValidator` + `ComplianceValidator` + `FatooraComplianceService` overlap in validation responsibility. | `Fatoora/Services/` | Consolidate; one class per concern. |
| **Q-8** | **Inconsistent exception handling.** Some services throw typed domain exceptions (`FatooraException::missingCredentials`), others `\RuntimeException`, others return `null` on failure (`CertificateService::getExpiryDate()` swallows `\Exception`). Silent-null returns hide failures from callers. | Across `Fatoora/Services/` | Standardise: domain exceptions for domain failures, never a bare `catch (\Exception) { return null; }`. |
| **Q-9** | **Large classes.** `FatooraSubmissionService` and `CertificateService` both exceed the 800-line guideline in the project's own coding standards. `CertificateService` mixes CSR generation, parsing, revocation checking and chain verification — four responsibilities. | | Split `CertificateService` into `CsrGenerator`, `CertificateParser`, `RevocationChecker`, `ChainVerifier`. |
| **Q-10** | **Inconsistent null-safety at boundaries.** `ComplianceController::generate()` passes `$this->tenant->getOrganization()` (nullable) into `$this->submission->generate($invoice, $organization)` without a null check. Reachable if a JWT carries `org_id` for a deleted organization. | `ComplianceController.php` | Fail fast with a typed 403 when tenant context cannot be resolved. |

---

## 2. Complexity assessment

### 2.1 Essential vs accidental

| Complexity | Verdict |
|---|---|
| ZATCA XML/UBL 2.1 generation | **Essential** — the specification is genuinely complex |
| ICV / PIH hash chaining | **Essential** — mandated, and correctness-critical |
| XAdES + ECDSA secp256k1 signing | **Essential** |
| TLV QR encoding (tags 1–9) | **Essential** |
| CSID onboarding (CCSID → compliance check → PCSID) | **Essential** |
| Offline queue | **Essential** — ZATCA requires continued issuance during outages |
| Multi-jurisdiction engine abstraction | **Essential** — it is the product thesis |
| Environment variance tracking | **Justified** — sandbox/production divergence is a real, painful ZATCA problem |
| **Three licensing systems** | **Accidental** — [01 §5.2](01-DISCOVERY-AND-ARCHITECTURE.md#52-anti-pattern-three-licensing-systems) |
| **Two auth stacks, two API surfaces** | **Accidental** — [01 §5.5](01-DISCOVERY-AND-ARCHITECTURE.md#55-anti-pattern-two-contexts-for-one-fact) |
| **Two circuit breakers** | **Accidental** |
| **Speculative resilience services** | **Accidental** — [01 §5.3](01-DISCOVERY-AND-ARCHITECTURE.md#53-anti-pattern-speculative-resilience-infrastructure) |
| **Duplicated ZATCA logic in `erp-backend`** | **Accidental** — [01 §1.1](01-DISCOVERY-AND-ARCHITECTURE.md#11-the-duplication-problem) |

### 2.2 Disposition for `Fatoora/Services/` (44 classes)

| Action | Classes | Rationale |
|---|---|---|
| **Keep — core** | `XmlBuilder`, `InvoiceHasher`, `XadesSigner`, `EcdsaSigner`, `QrCodeGenerator`, `TlvEncoder`, `AtomicIcvManager`, `HashChainManager`, `CertificateService`*, `CsidOnboardingService`, `FatooraSubmissionService`*, `FatooraComplianceService`, `FatooraValidator`, `OfflineQueueManager`, `OfflineAwareSubmissionService`, `ClearanceStateManager`, `SubmissionService`, `DuplicateInvoiceDetector`, `TimestampValidator`, `EnvironmentVarianceTracker` | Essential to correctness or mandated. *Split per Q-9. |
| **Consolidate** | `CircuitBreaker` + `ClusterCircuitBreaker` → one; `ComplianceValidator` + `FatooraValidator` → one; `AuditQueryService` → the `Audits` domain | Duplicate remits. |
| **Defer or delete** | `BackPressureManager`, `KillSwitchManager`, `HashChainAnomalyDetector`, `ArchivedTenantReconstructor`, `ComplianceSnapshot`, `QueueHealthMonitor`, `KeyCompromiseHandler`, `FallbackHandler`, `CertificateLineageService`†, `VatPeriodTracker`†, `ComplianceLogger`‡ | Built ahead of demand; no production incident justifies their current shape. †Verify for callers before removal. ‡Keep if it is the sanitisation chokepoint ([L-4](02-SECURITY-AUDIT.md#4-low-findings)). |

**Expected reduction:** ~25% of the compliance domain's class count, with no loss of shipped capability.

### 2.3 Re-namespacing

Replace the flat 44-class directory with:

```
Fatoora/
├── Signing/       XadesSigner · EcdsaSigner · InvoiceHasher
├── Xml/           XmlBuilder · SafeXmlLoader · TextNormalizer
├── Qr/            QrCodeGenerator · TlvEncoder
├── Certificates/  CsrGenerator · CertificateParser · RevocationChecker ·
│                  ChainVerifier · CsidOnboardingService
├── Chain/         AtomicIcvManager · HashChainManager
├── Submission/    FatooraSubmissionService · ClearanceStateManager ·
│                  DuplicateInvoiceDetector · OfflineQueueManager
└── Resilience/    CircuitBreaker · EnvironmentVarianceTracker
```

Complexity **S**; migration impact internal-only.

---

## 3. Performance review

No profiling was performed. The following are static observations with expected impact.

| ID | Issue | Impact | Recommendation | Target |
|---|---|---|---|---|
| **P-1** | **Synchronous DB write per authenticated request.** `ApiKey::recordUsage()` issues an `UPDATE` on every request; `ValidateLicense` additionally writes a `UsageEvent` row and calls `recordApiCall()`. That is up to **three writes before any business work** on the licence path. | Row contention on hot keys; latency floor; unbounded `usage_events` growth. | Buffer in Redis, flush per minute. Make `UsageEvent` an async job or a batched insert. | ≤1 write/request |
| **P-2** | **Signing credentials read from disk and decrypted on every submission.** `getSigningCredentials()` performs `Storage::exists()` + `get()` + `decrypt()` per invoice. | Adds file I/O and AES work to the critical path; worsens under the KMS migration if not cached. | Cache the decrypted key in a request-scoped (never cross-request) container binding; if caching across requests, use an encrypted in-memory store with short TTL and explicit invalidation on certificate rotation. | — |
| **P-3** | **Per-submission outbound revocation fetch.** `validateForSubmission()` may perform an OCSP or CRL network call per invoice, with a 10s timeout. | A slow OCSP responder stalls the entire submission pipeline. | Cache revocation status for the CRL's validity window (typically hours). Treat revocation as a **background** check with a cached verdict, not an inline blocker. | p99 signing < 200 ms |
| **P-4** | **`database` queue and cache drivers are the documented default.** `.env.example` sets `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database` while `ZATCA_QUEUE_CONNECTION=redis`. | The DB becomes the bottleneck for queue polling and cache reads under submission load; the split configuration is confusing. | Make Redis the documented default for queue, cache and session in production. Keep `database` only for local development. | — |
| **P-5** | **N+1 risk in dashboard/admin aggregation.** `AdminController::organizations()` paginates 20 organizations then issues follow-up statistics queries keyed by `$orgIds`. Correct in shape, but `CustomerPortalController::dashboard()` issues **six separate `COUNT` queries** for a single view. | Six round-trips per portal page load; grows with each new stat. | Single grouped aggregate query (`SELECT state, COUNT(*) … GROUP BY state`). | 1 query |
| **P-6** | **XML processing is fully in-memory.** `DOMDocument` load → hash → sign → re-serialise, several times per invoice across `InvoiceHasher`, `XadesSigner` and `FatooraComplianceService`. | Memory scales with invoice line count; large B2B invoices (hundreds of lines) risk exceeding `memory_limit` under concurrency. | Parse once, pass the `DOMDocument` through the pipeline instead of re-parsing the string at each stage. Establish a documented maximum invoice size and enforce it at validation. | Parse once per invoice |
| **P-7** | **No index verification for the hot query paths.** Dedicated index migrations exist (`2026_02_02_000002_add_performance_indexes`), and an `IndexHealthCheck` command exists — but there is no evidence of `EXPLAIN` verification on `invoices(organization_id, created_at)`, `invoice_submissions(organization_id, state)`, or `usage_events(license_id, created_at)`. | Unknown. | Run `EXPLAIN` on the ten most frequent queries; document the results in `docs/PERFORMANCE.md`. | No full scans on tables >100k rows |
| **P-8** | **`usage_events` and `submission_state_logs` are append-only with no documented retention.** `PartitionMaintenance` exists but the strategy is undocumented. | Unbounded growth; eventual query degradation and storage cost. | Document the partitioning scheme; define retention (e.g. 90 days hot, archive to S3 beyond); test the archival path. | — |

**Recommended measurement before optimising.** Install a profiler on the submission path and publish a baseline: p50/p95/p99 for `POST /v1/pipeline/submit`, broken down by credential load, XML build, hash, sign, ZATCA round-trip. Optimise against that, not against this list.

---

## 4. Maintainability review

| Dimension | Score | Assessment |
|---|:---:|---|
| **Ease of understanding** | 🟡 6/10 | Domain naming is excellent; the three-licensing-system tangle and 44-class service directory are the drags. |
| **Ease of onboarding** | 🔴 4/10 | `composer setup` exists and is good. But: `.env.example` is 300+ lines with no required/optional distinction; the OpenAPI spec is wrong; the README overclaims SDKs. A new developer's first day is spent discovering that documentation does not match reality. |
| **Ease of modification** | 🟡 5/10 | Adding a *jurisdiction* is easy (the engine abstraction earns its keep). Adding an *endpoint* means doing it twice — once per API surface — with two auth models and two scope vocabularies. |
| **Ease of debugging** | 🟡 6/10 | Structured exceptions with stable codes, a request-ID convention and Prometheus metrics are all present. Undermined by silent-null error swallowing (Q-8) and security controls that fail silently ([H-3](02-SECURITY-AUDIT.md#-h-3--crl-revocation-check-is-silently-non-functional)). |
| **Ease of extension** | 🟢 7/10 | `ComplianceEngine` is a clean seam. Webhook events are properly modelled. |
| **Ease of testing** | 🟡 5/10 | Constructor injection is used well; direct `config()`/facade calls in domain services (Q-6) and filesystem coupling in credential loading make isolation harder than it should be. |
| **Configuration simplicity** | 🟡 5/10 | `config/fatoora.php` is a model of good configuration. There are simply too many knobs overall (`ZATCA_BP_TOKENS_PER_SEC` for a system with no load). |

**Highest-leverage maintainability action:** make the documentation true. An accurate OpenAPI spec and an honest README will do more for onboarding time than any refactor on this list.

---

## 5. Consistency audit & unified standard

### 5.1 Observed inconsistencies

| Area | Divergence |
|---|---|
| **Response envelope** | `ApiResponse::success()` returns `{success, data, message}`; the exception handler returns `{success:false, error:{code,message,category}}`; `ValidateLicense` returns `{error:true, error_code, message}` — **three shapes**. |
| **Error codes** | `ErrorCode` enum (Fatoora) · `LicenseException::errorCode` strings · bare HTTP statuses. |
| **Tenant access** | `TenantResolver` (JWT) vs `$request->attributes` (licence). |
| **Scopes** | `ApiKey::hasScope()` with `['*']` vs `License::hasScope()` with the `ApiScope` enum and implied scopes. |
| **Data access** | Eloquent in API controllers; raw `DB::table()` in web controllers. |
| **Validation** | FormRequests (`CreateInvoiceRequest`) in some controllers; inline checks in others. |
| **Naming** | `compliance/sa` vs `compliance/zatca`; `Fatoora*` vs `Zatca*` (`ZatcaException`, `zatca:process-offline`, `config/fatoora.php`). |
| **Route paths** | `/api/compliance/sa/submit/{id}` vs `/api/v1/compliance/submit/{id}` for the same operation. |
| **Nullability** | Some services return `null` on failure, others throw. |

### 5.2 The unified standard

Adopt and enforce these. They are deliberately few.

**1 — One response envelope, always.**
```jsonc
// success
{ "data": { … }, "meta": { "request_id": "…" } }
// paginated
{ "data": [ … ], "meta": { "request_id": "…", "page": 1, "per_page": 15, "total": 120 } }
// error
{ "error": { "code": "INVOICE_VALIDATION_FAILED", "message": "…",
             "details": [ … ], "doc_url": "https://docs.masaar.sa/errors/INVOICE_VALIDATION_FAILED" },
  "meta": { "request_id": "…" } }
```
Never `success: true` alongside a 4xx. Never a 200 carrying an error.

**2 — One error-code vocabulary.** A single `ErrorCode` enum, `UPPER_SNAKE_CASE`, namespaced by domain (`INVOICE_*`, `CERT_*`, `AUTH_*`, `QUOTA_*`). Every code has a documentation page. Codes are part of the public contract and are never renamed without a version bump.

**3 — One auth context.** A request-scoped `AuthContext` (tenant, actor, credential type, scopes, environment), populated by whichever middleware authenticated. Nothing reads `$request->attributes` or a resolver singleton directly.

**4 — One scope vocabulary.** The `ApiScope` enum. No untyped `['*']`.

**5 — Naming.** `Fatoora` = the ZATCA *system* (internal class names). `SA` = the *jurisdiction* (public API paths). Never `Zatca` in new code. Public paths use ISO-3166 codes: `/v1/{jurisdiction}/…`.

**6 — Validation at the boundary.** Every write endpoint takes a FormRequest. No inline `$request->has()` checks in controllers.

**7 — Eloquent for all tenant-scoped data.** Raw `DB::table()` only for reporting aggregates that are explicitly tenant-filtered and reviewed.

**8 — Errors throw.** Domain failures raise typed domain exceptions. `catch (\Exception) { return null; }` is prohibited.

**9 — Structured logging.** Every log line carries `request_id`, `organization_id` and `jurisdiction`. All compliance-domain logging goes through `ComplianceLogger`, which sanitises.

Enforce 1, 4, 6, 7 and 8 with static analysis (PHPStan level 6+ plus custom rules) in CI, not code review.

---

## 6. Testing strategy

### 6.1 Current state

**29 test files against 188 source files.** Distribution:

| Area | Files | Assessment |
|---|:---:|---|
| Fatoora domain (hasher, QR, TLV, validator, response) | 7 | 🟢 Meaningful spec-level coverage — `InvoiceHashSpecTest`, `QrCodeSpecTest` test against the specification, which is the right instinct |
| FTA / multi-jurisdiction routing | 5 | 🟢 Recent and reasonable |
| Organization / tenancy | 5 | 🟡 Unit-level only; no cross-tenant leakage tests |
| Auth | 1 | 🔴 `JwtAuthenticatesUsersTest` only |
| **Licensing** | **0** | 🔴 Three licensing systems, zero tests |
| **Authorization / scopes** | **0** | 🔴 None |
| **Certificates / signing / revocation** | **0** | 🔴 None — this is why [H-3](02-SECURITY-AUDIT.md#-h-3--crl-revocation-check-is-silently-non-functional) survived |
| **Webhooks** | **0** | 🔴 None |
| **Offline queue** | **0** | 🔴 None |
| **Web controllers (`/admin`, `/portal`)** | **0** | 🔴 None — this is why [C-1](02-SECURITY-AUDIT.md#-c-1--the-admin-web-console-has-no-authentication) and [C-2](02-SECURITY-AUDIT.md#-c-2--customer-portal-reads-tenant-identity-from-a-query-parameter-unauthenticated) survived |

**The correlation is not coincidental.** Every Critical and High finding in this audit sits in an area with zero test coverage. The test suite is not merely thin — it is thin in exactly the places where the defects are.

### 6.2 Target strategy

**Coverage targets** (line coverage is the floor, not the goal):

| Layer | Target | Rationale |
|---|:---:|---|
| Signing, hashing, QR, XML | **95%** | Correctness here is legally binding |
| Auth, authorization, tenancy | **95%** | Every Critical finding lives here |
| Certificates & revocation | **90%** | Silent failure is the danger |
| Compliance engines & router | **85%** | Product core |
| Licensing & quota | **85%** | Revenue-critical |
| Controllers & HTTP | **80%** | Contract enforcement |
| Overall | **80%** | Project standard |

**Test types, in priority order:**

1. **Route-posture tests (build first, highest value).** A single data-driven test that enumerates *every registered route* and asserts its expected authentication and authorization posture. Any new route without a declared posture fails the build. This class of test makes C-1 and C-2 structurally impossible to reintroduce.

2. **Cross-tenant isolation tests.** For each tenant-scoped model: seed two organizations, act as A, assert B's rows are invisible — via direct query, relationship traversal, API endpoint, and queue job. Required to validate the [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) fix.

3. **ZATCA conformance tests.** Validate generated XML against the official UBL 2.1 XSD **and** ZATCA's schematron rules, using ZATCA's published sample invoice set as golden fixtures. Assert hash, signature and QR byte-for-byte against known-good outputs. **This is the test suite that determines whether the product works** — its absence is the largest single risk to the compliance claim.

4. **Certificate & revocation tests.** Fixtures for: valid, expired, revoked, wrong-curve, malformed. A revoked-certificate fixture would have caught H-3 immediately.

5. **Contract tests against the OpenAPI spec.** Every endpoint's response validated against the schema, in CI. This is what keeps the spec honest ([04 §1](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#1-documentation-audit)) rather than aspirational.

6. **Integration tests with a ZATCA mock.** A recorded-fixture server covering success, rejection with each error code, timeout, 5xx, and malformed response. Enables testing the circuit breaker and offline queue deterministically.

7. **Idempotency & concurrency tests.** Two concurrent submissions of the same invoice produce one submission. Concurrent ICV allocation never duplicates — `AtomicIcvManager` claims atomicity; prove it under parallel load.

8. **Security regression tests.** One test per Critical/High finding in [02](02-SECURITY-AUDIT.md), asserting the vulnerability is closed. This converts the audit into a permanent guard.

9. **Performance tests.** Assert p95 signing latency and a memory ceiling for a 500-line invoice (P-6).

### 6.3 Sequencing

| Order | Work | Why first |
|:---:|---|---|
| 1 | Route-posture + cross-tenant isolation tests | Locks the Critical fixes; cheap to write |
| 2 | Certificate/revocation fixtures | Closes H-3 and prevents recurrence |
| 3 | ZATCA conformance suite with official fixtures | Validates the core product claim |
| 4 | OpenAPI contract tests | Makes the spec trustworthy, unblocking SDK generation |
| 5 | Licensing, webhook, offline-queue coverage | Fills the remaining zero-coverage areas |
| 6 | Concurrency, performance | Once behaviour is locked |

Pest 4 and `pest-plugin-laravel` are already installed. `phpunit.xml` exists. The tooling is not the obstacle.

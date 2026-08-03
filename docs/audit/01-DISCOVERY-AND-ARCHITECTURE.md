# Discovery & Architecture Assessment

**Audit date:** 2026-08-03
**Related:** [00-EXECUTIVE-SUMMARY](00-EXECUTIVE-SUMMARY.md) · [02-SECURITY-AUDIT](02-SECURITY-AUDIT.md) · [05-TARGET-ARCHITECTURE](05-TARGET-ARCHITECTURE-AND-ROADMAP.md)

---

## 1. System landscape

Three repositories, one product line.

| Repository | Role | Stack | Size |
|---|---|---|---|
| **Masaar** | GCC e-invoicing compliance platform — the product under audit | Laravel 12, PHP 8.2+, MySQL, Redis | 188 PHP files, 35 migrations, 29 test files |
| **erp-backend** | Multi-tenant ERP; the platform's first and largest consumer | Laravel 12, PHP 8.2+ | ~2,100 PHP files |
| **erp-frontend** | React monorepo — staff, admin and vendor portals for the ERP | Turborepo, pnpm, React, Tailwind, TanStack Query | 3 apps, 3 shared packages |

```
┌───────────────────────────────────────────────────────────────────────┐
│                            erp-frontend                               │
│   apps/staff (5173)      apps/admin (5174)     apps/portal (5181)     │
│   ────────────────────────────────────────────────────────────────    │
│   packages/ui  ·  packages/api-client  ·  packages/types              │
└──────────────────────────────┬────────────────────────────────────────┘
                               │ REST /api/v1 + JWT
                               ▼
┌───────────────────────────────────────────────────────────────────────┐
│                            erp-backend                                │
│   Sales · Accounting · HR · Inventory · Manufacturing · CRM · …       │
│                                                                       │
│   PostInvoiceOrchestrator ──▶ CompliPayClient ─────────┐              │
│   ⚠ ZatcaClientV1 + ZatcaInvoiceTransformer (duplicate)│              │
└────────────────────────────────────────────────────────┼──────────────┘
                                                         │ POST /v1/pipeline/submit
                                                         │ X-API-Key + X-API-Secret
                                                         ▼
┌───────────────────────────────────────────────────────────────────────┐
│                              MASAAR                                   │
│                                                                       │
│  Http ──▶ Domains/Compliance/ComplianceRouter                         │
│                    ├── FatooraEngine  (SA — ZATCA Phase 2)  ✅        │
│                    ├── FtaEngine      (AE — Peppol PINT)    🚧        │
│                    └── (QA — GTA)                            📋       │
│                                                                       │
│  Domains: Auth · Organization · Invoice · Licensing · Webhook · Log   │
└───────────────────────────┬───────────────────────────────────────────┘
                            │ mTLS + Basic (CSID)
                            ▼
              ┌─────────────────────────────┐
              │  ZATCA Fatoora  ·  UAE FTA  │
              └─────────────────────────────┘
```

### 1.1 The duplication problem

`erp-backend` integrates with Masaar through `CompliPayClient` → `POST /pipeline/submit`. That is the correct seam: one HTTP call, circuit-breaker protected, with a `ComplianceResult` value object.

But `erp-backend` **also** carries `app/Services/Compliance/ZatcaClientV1.php` (46 lines) and `ZatcaInvoiceTransformer.php` (159 lines) — a second, thinner implementation of the same invoice→ZATCA mapping.

Two implementations of a *regulated* mapping will drift. When they drift, the symptom is ZATCA rejecting invoices in production, and the diagnosis requires knowing which of two code paths produced the payload. This also undermines the commercial thesis: Masaar can only be sold as a standalone product if it is genuinely the sole owner of compliance knowledge.

**Recommendation:** `erp-backend` should hold no ZATCA domain knowledge beyond assembling the Masaar request body. Delete `ZatcaClientV1`; keep `CompliPayClient` and `ZatcaInvoiceTransformer` reduced to a pure DTO mapper.

---

## 2. Masaar internal structure

```
app/
├── Domains/                         ← domain-driven core
│   ├── Auth/            Contracts · DTOs · Models(ApiKey) · Services
│   ├── Compliance/
│   │   ├── Contracts/   ComplianceEngine · SubmissionResult · ValidationResult
│   │   ├── ComplianceRouter.php     ← jurisdiction strategy selector
│   │   ├── Fatoora/     (SA) Client · Config · DTOs · Enums · Events ·
│   │   │                Helpers · Jobs · Listeners · Models · Services ×44
│   │   └── FTA/         (AE) DTOs · Enums · Jobs · Models · Services ×3
│   ├── Invoice/         Enums · Models
│   ├── Licensing/       Console · Enums · Exceptions · Http · Models · Services
│   ├── Logging/         Handlers · Services
│   ├── Organization/    Models · Services · ValueObjects
│   └── Webhook/         Events · Models · Services
├── Http/                Controllers(Api|Web) · Middleware ×7 · Requests · Responses
├── Services/            Licensing/ · PipelineService · LicenseRegistrationService
├── Models/              User · LicenseRegistration · LicenseRegistrationAudit
├── Audits/              AuditLog · AuditService
├── Console/Commands/    ×16
└── Providers/           App · Compliance · Event
```

**The domain layout is sound.** Bounded contexts are named after the business, not after Laravel's default folders. DTOs, enums, value objects and contracts are used correctly, and `declare(strict_types=1)` is applied consistently in the domain layer. Controllers are thin and delegate to services. This is above-average Laravel architecture.

Three structural problems sit on top of it (§5).

---

## 3. Request lifecycle

Masaar exposes **two authentication paths reaching largely the same operations.**

```
                        ┌──────────────────────────────────────┐
   ── /api/*  ────────▶ │ jwt.auth                             │
      (JWT, human/UI)   │  ├─ JWTAuth::parseToken()            │
                        │  ├─ auth()->setUser($user)           │
                        │  └─ TenantResolver::setContext(      │
                        │        OrganizationContext::from     │
                        │        Claims(org_id, role))         │
                        │ rate.api  (60/min per user or IP)    │
                        └──────────────┬───────────────────────┘
                                       │
   ── /api/v1/* ───────▶┌──────────────┴───────────────────────┐
      (API key,         │ license                              │
       server-to-server)│  ├─ extract key + secret             │
                        │  ├─ LicenseValidationService         │
                        │  ├─ environment / scope checks       │
                        │  ├─ UsageMeteringService rate limit  │
                        │  └─ request->attributes: license,    │
                        │       organization_id, request_id    │
                        │ scope:xxx · license.quota · rate.api │
                        └──────────────┬───────────────────────┘
                                       ▼
                              Controller (thin)
                                       ▼
                     Invoice::where('organization_id', …)   ⚠ C-4
                                       ▼
                          Domain service / ComplianceRouter
                                       ▼
                        Engine (Fatoora | FTA) ──▶ Authority
                                       ▼
                       Events ──▶ Listeners ──▶ WebhookService
```

Note the divergence: the JWT path populates `TenantResolver` (a singleton the controllers read); the licence path populates `$request->attributes`. **Two different mechanisms carry the same fact.** A controller written against one will silently receive `null` under the other. This is the root of the inconsistency described in §5.2 and a contributing factor to C-4.

### 3.1 Route surface inventory

| Prefix | Auth | Purpose | Notes |
|---|---|---|---|
| `/api/health`, `/api/license/status` | none | liveness, licence status | intentional |
| `/api/metrics` | none | Prometheus | ⚠ [H-6](02-SECURITY-AUDIT.md#-h-6--unauthenticated-prometheus-metrics-endpoint) |
| `/api/auth/*` | none → JWT | register, login, refresh, me | |
| `/api/invoices`, `/api/compliance/sa\|ae/*`, `/api/organizations`, `/api/webhooks`, `/api/api-keys`, `/api/dashboard/*` | `jwt.auth` | primary UI surface | |
| `/api/compliance/zatca\|uae-fta/*` | `jwt.auth` | deprecated → 301 | still documented in OpenAPI ⚠ |
| `/api/v1/*` | `license` + `scope` | server-to-server | duplicates the above |
| `/api/admin/dashboard/*` | `jwt.auth` + `admin` | platform admin JSON | correctly protected |
| `/admin/*` (web) | **none** | Blade admin console | 🔴 [C-1](02-SECURITY-AUDIT.md#-c-1--the-admin-web-console-has-no-authentication) |
| `/portal/*` (web) | **none** | Blade customer portal | 🔴 [C-2](02-SECURITY-AUDIT.md#-c-2--customer-portal-reads-tenant-identity-from-a-query-parameter-unauthenticated) |
| `routes/licensing.php` | mixed | licence registration | third licensing path |

---

## 4. Invoice & compliance lifecycle

### 4.1 Invoice state machine

```
  Draft ──▶ Issued ──▶ [ compliance pipeline ] ──▶ Cleared | Reported
    │          │                                       │
    │          │                                       └─▶ Rejected ──▶ retry
    └─ editable └─ immutable (isEditable() === false)
```

`InvoiceController::update()` and `destroy()` correctly gate on `isEditable()`, so post-issuance immutability is enforced — an important compliance property.

### 4.2 Submission pipeline (Saudi Arabia / Fatoora)

```
 PipelineController::submit  or  ComplianceController::submit
              │
              ▼
   FatooraSubmissionService
              │
     ┌────────┼─────────────────────────────────────────┐
     ▼        ▼                                         ▼
 getSigningCredentials()          validateCertificate()  DuplicateInvoiceDetector
  branch → org fallback            expiry + revocation
  Storage::disk('local')           ⚠ H-1, H-3
  encrypt()/decrypt()
              │
              ▼
   AtomicIcvManager ──▶ HashChainManager   (ICV increment + PIH linkage)
              │
              ▼
   XmlBuilder  ──▶ UBL 2.1 invoice XML
              │
              ▼
   InvoiceHasher ──▶ canonical hash (removes UBLExtensions + Signature)
              │
              ▼
   XadesSigner ──▶ EcdsaSigner (secp256k1 / SHA-256)  ──▶ signed XML
              │
              ▼
   QrCodeGenerator ──▶ TlvEncoder (tags 1-9)
              │
              ▼
   CircuitBreaker ──▶ FatooraClient ──▶ ZATCA clearance/reporting API
              │                              │
              │                    (unavailable)
              │                              ▼
              │                     OfflineQueueManager
              │                     ProcessOfflineQueue (scheduled)
              ▼
   InvoiceSubmission + SubmissionStateLog + SubmissionIdempotency
              │
              ▼
   Events: InvoiceCleared | Reported | Rejected | Warning | Failed
              │
              ▼
   DispatchInvoiceWebhook ──▶ WebhookService ──▶ customer endpoint
```

**This pipeline is the strongest part of the codebase.** The ordering is correct per the ZATCA specification (hash over canonical XML *before* signature insertion; ICV/PIH chain maintained atomically; QR built from the signed artefacts). The offline queue is not gold-plating — ZATCA explicitly requires taxpayers to continue issuing invoices when the platform is unreachable, and to submit them later.

### 4.3 Multi-jurisdiction routing

```php
interface ComplianceEngine {
    public function supports(string $jurisdiction): bool;
    public function submit(Invoice $i, ComplianceProfile $p): SubmissionResult;
    public function retry(string $submissionId, ComplianceProfile $p): SubmissionResult;
    public function checkStatus(string $submissionId, ComplianceProfile $p): SubmissionResult;
    public function validate(Invoice $i, ComplianceProfile $p): ValidationResult;
}
```

`ComplianceRouter` selects an engine by `ComplianceProfile->jurisdiction`. `FatooraEngine` (SA) and `FtaEngine` (AE) implement it; Qatar is planned.

**This is the single best design decision in the project.** It is a clean Strategy pattern with a stable five-method contract, it made UAE support additive rather than a fork, and it is the technical basis for the "one API, every GCC jurisdiction" positioning in [04 §5](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#5-product-strategy). Preserve it and hold the line on the interface.

One caution: `FtaEngine` has 3 services against Fatoora's 44. The abstraction is currently proven by one full implementation and one partial. Resist adding methods to `ComplianceEngine` for jurisdiction-specific needs — push those behind the engine.

---

## 5. Architecture assessment

### 5.1 Principle scorecard

| Principle | Score | Assessment |
|---|:---:|---|
| **Separation of concerns** | 🟢 8/10 | Domains are business-named; controllers are thin; DTOs and value objects used properly. |
| **Domain boundaries** | 🟢 8/10 | `Compliance`, `Invoice`, `Organization`, `Webhook` are genuine bounded contexts. |
| **Dependency direction** | 🟡 6/10 | Mostly inward. Violations: `CertificateService` calls `config()` and the `\Log` facade directly; several domain services reach for `Storage`. |
| **SOLID — Interface Segregation** | 🟢 8/10 | `ComplianceEngine` is a well-sized five-method contract. |
| **SOLID — Single Responsibility** | 🟡 5/10 | Honoured at class level, violated at package level: `Fatoora/Services/` holds 44 classes with overlapping remits. |
| **DRY** | 🔴 4/10 | Two auth stacks, two API surfaces, three licensing systems, two circuit breakers, duplicated ZATCA logic in `erp-backend`. |
| **KISS** | 🔴 3/10 | See §5.2. |
| **YAGNI** | 🔴 2/10 | See §5.3. |
| **Cohesion** | 🟡 6/10 | High within `Fatoora/`, but the package is far too large to navigate. |
| **Coupling** | 🟢 7/10 | Engines are properly decoupled behind the router. |
| **Extensibility** | 🟢 8/10 | Adding a jurisdiction is genuinely additive. |
| **Testability** | 🟡 5/10 | Constructor injection is used well; static `config()` and facade calls in domain services impede isolation. |

### 5.2 Anti-pattern: three licensing systems

| System | Location | Concept |
|---|---|---|
| Customer API licences | `app/Domains/Licensing/` — `License`, `LicenseUsage`, `LicenseAuditLog`, `LicenseRateLimit`, `UsageEvent`, 4 middleware, 3 services | What a customer bought (tier, scopes, quota, environment) |
| Platform right-to-run | `app/Services/Licensing/PlatformLicenseService`, `ValidatePlatformLicense` middleware, `CheckPlatformLicense` + `GeneratePlatformLicense` + `ReportPlatformUsage` commands, `config/platform-license.php` | Masaar's own licence for on-prem deployments |
| Self-service registration | `app/Models/LicenseRegistration`, `LicenseRegistrationAudit`, `LicenseRegistrationService`, `LicenseRegistrationController`, `routes/licensing.php` | Signup/activation flow |

Three data models, three middleware paths, ~25 files, all called "license." A new engineer cannot determine which licence gates a given route without reading five files.

**Recommendation:** collapse to two, named for what they are.
- **`Subscription`** — plan, quota, scopes, environment. Absorbs system 1 and system 3's persistence.
- **`DeploymentLicence`** — right-to-run, only meaningful for on-prem. Keep system 2, rename it, and exclude it from SaaS builds behind a feature flag.

Migration impact: **medium.** The models are separate, so this is largely deletion and renaming rather than data migration. Do it before the customer base grows.

### 5.3 Anti-pattern: speculative resilience infrastructure

`Fatoora/Services/` contains 44 classes. Among them:

| Class | Assessment |
|---|---|
| `CircuitBreaker` **and** `ClusterCircuitBreaker` | Two implementations of one concern. Keep one. |
| `BackPressureManager` | Token-bucket throttling with 4 env knobs, for a system not yet under load. |
| `KillSwitchManager` | Per-tenant blast-radius control, with no incident history to justify its shape. |
| `HashChainAnomalyDetector` | Detects anomalies in a chain that has not yet been written in production. |
| `ArchivedTenantReconstructor` | Reconstructs tenants that have not yet been archived. |
| `ComplianceSnapshot` | Purpose not evidenced by any caller in the audited paths. |
| `QueueHealthMonitor` | Overlaps `BackPressureManager` and the Prometheus metrics endpoint. |
| `EnvironmentVarianceTracker` | Genuinely useful — sandbox/production divergence is a real ZATCA problem. **Keep.** |
| `OfflineQueueManager`, `OfflineAwareSubmissionService` | Required by the ZATCA specification. **Keep.** |
| `AtomicIcvManager`, `HashChainManager` | Core correctness. **Keep.** |

This machinery was built before the load it protects against exists, while the test suite does not cover authentication and the SDKs are empty. It is not free — every class is surface area to read, maintain, secure and onboard against.

**Recommendation:** keep one circuit breaker, the offline queue, the ICV/hash-chain services and the variance tracker. Move the remainder to a `deferred/` namespace excluded from autoload, or delete them (git history preserves the work). Reintroduce individually when a production incident justifies it.

### 5.4 Anti-pattern: dead security infrastructure

`TenantIsolationGuard` — 300 lines, zero call sites. Covered as [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth). Architecturally the lesson is broader: **an unused abstraction that appears to enforce an invariant is worse than no abstraction**, because reviewers infer protection that does not exist.

### 5.5 Anti-pattern: two contexts for one fact

`TenantResolver` (singleton, JWT path) and `$request->attributes['organization_id']` (licence path) both carry the current tenant. Controllers depend on the former; the licence middleware populates only the latter.

**Recommendation:** one `AuthContext` value object — tenant ID, actor, credential type, granted scopes, environment — resolved by whichever middleware authenticated, bound request-scoped in the container, and injected everywhere. This is the precondition for both the auth unification in [05 §2](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#2-target-architecture) and the `BelongsToTenant` global scope in C-4.

---

## 6. Configuration, data and deployment

**Configuration.** 16 config files. `config/fatoora.php` is genuinely good — well-commented, every value environment-overridable, sensible defaults, and it documents *why* (e.g. the idempotency scope declaration). `.env.example` is 300+ lines, which is thorough but past the point where a new developer can tell what is required versus optional. Split into `.env.example` (required to boot) and `docs/CONFIGURATION.md` (the full reference). Fix `APP_DEBUG=true` / `APP_ENV=local` in the example ([M-6](02-SECURITY-AUDIT.md#3-medium-findings)).

**Data model.** 35 migrations. Reviewed positives: dedicated performance-index and foreign-key migrations (`2026_02_02_000002`, `_000003`) show deliberate attention; `certificate_lineage` with `activated_at`/`revoked_at`/`certificate_hash` is a thoughtful model for certificate rotation; `submission_idempotency` and the hash-chain tables are properly indexed on `(organization_id, certificate_id)`.

Concerns: `.env.example` defaults `QUEUE_CONNECTION=database` and `CACHE_STORE=database`, which will not carry production submission volume — Redis is configured elsewhere (`ZATCA_QUEUE_CONNECTION=redis`), so the defaults are inconsistent with the intent. `PartitionMaintenance` exists as a command but the partitioning strategy is not documented.

**Deployment.** `docker-compose.prod.yml` defines `app`, `db`, `redis`, `traefik` and a Let's Encrypt sidecar — a competent single-host topology. It is **single-replica**, which is currently required by [H-1](02-SECURITY-AUDIT.md#-h-1--signing-keys-are-encrypted-with-app_key-on-a-local-filesystem-disk): signing credentials live on a local disk, so scaling `app` horizontally would break signing for tenants onboarded on another replica. Fixing H-1 is therefore a scalability prerequisite, not only a security one.

---

## 7. Summary of architectural recommendations

| # | Recommendation | Rationale | Trade-off | Complexity | Migration impact |
|---|---|---|---|:---:|---|
| A-1 | Introduce `AuthContext`; unify JWT and licence auth onto it | Removes the two-mechanism split (§5.5); precondition for A-2 and C-4 | Touches every controller constructor | **M** | Internal only; no API change |
| A-2 | Collapse `/api/*` and `/api/v1/*` into one `/v1/*` surface | Halves the build/test/document cost of every endpoint | Requires a deprecation window for existing consumers | **M** | Breaking; needs 2-release notice |
| A-3 | `BelongsToTenant` global scope; delete `TenantIsolationGuard` | Structural enforcement of the core invariant ([C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth)) | Requires an audited escape hatch for system jobs | **M** | Internal; needs careful job review |
| A-4 | Three licensing systems → two (`Subscription`, `DeploymentLicence`) | Removes the largest source of accidental complexity (§5.2) | One-time migration and renaming | **M** | Internal; do before customer growth |
| A-5 | Retire speculative resilience services (§5.3) | Reduces surface area ~25%; refocuses effort on shipping blockers | Rebuilding later costs more than keeping | **S** | None — no production callers |
| A-6 | Split `Fatoora/Services/` into `Signing/`, `Submission/`, `Certificates/`, `Chain/`, `Resilience/` | 44 flat classes are unnavigable | Namespace churn in one release | **S** | Internal only |
| A-7 | Remove `ZatcaClientV1` from `erp-backend`; Masaar owns all ZATCA knowledge | Prevents drift in a regulated mapping (§1.1) | ERP loses a fallback path | **S** | Cross-repo; coordinate release |
| A-8 | Move signing keys to KMS envelope encryption, off local disk | Security ([H-1](02-SECURITY-AUDIT.md#-h-1--signing-keys-are-encrypted-with-app_key-on-a-local-filesystem-disk)) **and** unblocks horizontal scaling | Adds a KMS dependency and cost | **M** | Requires a re-encryption migration |

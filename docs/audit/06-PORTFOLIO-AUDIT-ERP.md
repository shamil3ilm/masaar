# Portfolio Audit — `erp-backend` & `erp-frontend`

**Audit date:** 2026-08-18 · **Method:** read-only static investigation. No code was modified.
**Related:** [00-EXECUTIVE-SUMMARY](00-EXECUTIVE-SUMMARY.md) · [07-KNOWLEDGE-EXTRACTION](07-KNOWLEDGE-EXTRACTION.md) · [08-CLEANUP-PLAN](08-CLEANUP-PLAN.md)

> The first audit pass (documents 00–05) covered **Masaar**. This document covers the other two repositories in the portfolio.

---

## 1. Headline verdict

| Repository | Verdict | One-line summary |
|---|---|---|
| **erp-backend** | 🟢 **Architecturally the strongest asset in the portfolio** | Genuine SAP-parity domain depth with enforced architectural rules; held back by partial adoption of its own best patterns and a tenancy scope that silently disengages outside HTTP |
| **erp-frontend** | 🔴 **A shell, not an application** | 38 application source files against 3,155 backend routes; the design system is the only substantive part |
| **Masaar** | 🔴 **Not production-ready** | See [00-EXECUTIVE-SUMMARY](00-EXECUTIVE-SUMMARY.md) — 4 Critical, 6 High |

**The most important finding of this pass is a reversal of expectations.** `erp-backend` — the repository nobody asked me to scrutinise first — contains better engineering than the flagship compliance platform. Its `docs/architecture-rules.md` is a genuinely excellent artefact: eight numbered rules, each with a *Why*, a *Pattern*, an *Enforcement* mechanism, and an honest `[ENFORCED]` / `[PARTIALLY ADOPTED]` / `[ASPIRATIONAL]` marker. Very few codebases of any size have this. It should be the portfolio-wide standard, and Masaar should adopt it verbatim.

---

## 2. `erp-backend` — measured scale

Claims in the README were verified rather than accepted.

| Metric | README claims | Measured | Assessment |
|---|---|---|---|
| Models | 1,064 | 1,076 files | ✅ Accurate |
| Controllers | 384 | 387 | ✅ Accurate |
| Services | 386 | 426 | ✅ Understated |
| API routes | 3,360 | 3,155 route registrations (`apiResource` expands to ≥5 endpoints each, so the true endpoint count is higher) | ✅ Plausible |
| Migrations | 381 | 402 | ✅ Understated |
| Tests | "1,303 passing" | 185 files / ~2,140 test methods | ✅ Understated |

**Every discrepancy runs in the direction of under-claiming.** This is worth stating plainly because it is the exact opposite of Masaar, whose README advertises eleven SDKs that do not exist ([04 §1.1](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#11-documentation-that-is-wrong)). Two repositories in one portfolio with opposite documentation cultures is itself a finding — the honest one should set the standard.

### Domain surface

| Domain | Services | Domain | Services |
|---|:---:|---|:---:|
| Accounting | 71 | Purchase | 22 |
| Core (platform) | 63 | Compliance | 17 |
| HR | 46 | Tax | 15 |
| Manufacturing | 37 | Projects | 12 |
| Sales | 35 | Maintenance | 9 |
| Inventory | 25 | RealEstate / Ecommerce | 7 each |

Accounting alone covers document splitting, parallel ledgers, COPA profitability analysis, assessment and distribution cycles, material ledger, transfer pricing, XBRL, Zakat, MT940/CAMT.053 bank-statement parsing, dunning, and a financial-close cockpit. Manufacturing covers MRP, capacity planning, SPC, CAPA 8D, stability studies, subcontracting and repetitive manufacturing. **This is real ERP domain knowledge, not scaffolding**, and it is the portfolio's most valuable and least replaceable asset. It is catalogued in [07-KNOWLEDGE-EXTRACTION](07-KNOWLEDGE-EXTRACTION.md).

---

## 3. `erp-backend` — architecture assessment

### 3.1 What is genuinely strong

**Eight architectural rules with honest enforcement markers** (`docs/architecture-rules.md`). The rules themselves are correct and well-reasoned:

| Rule | Subject | Marker | Verified |
|:---:|---|---|---|
| 1 | Controllers validate and delegate | ENFORCED by convention | ✅ Spot-checked; holds |
| 2 | Cross-module flows use orchestrators | **PARTIALLY ADOPTED** | ⚠️ **1 orchestrator exists** — see §3.2 |
| 3 | No external calls inside transactions | ENFORCED by convention | ✅ `PostInvoiceOrchestrator` exemplifies it |
| 4 | Events must be idempotent | ENFORCED for existing listeners | ✅ 18 listeners carry CONTRACT docblocks |
| 5 | Multi-tenancy enforced at model level | ENFORCED | ⚠️ **Partially** — see §4.1 |
| 6 | State transitions must be guarded | ENFORCED | ✅ 15 models + trait, matches doc exactly |
| 7 | Idempotency required on financial writes | ENFORCED for critical ops | ✅ 3 operations, DB-unique-constraint mechanism |
| 8 | Models emit webhooks through the trait | ENFORCED for core models | ✅ 10 models |

**The middleware chain is correct and layered.** `auth:api → validate.jwt → check.organization → throttle:api → track.activity`, with `check.module:{module}` gating per domain and `check.permission` applied densely (115 occurrences in accounting routes alone). Security headers are appended globally. The ZATCA webhook receiver sits outside the auth group but is HMAC-verified by dedicated middleware and rate-limited — the correct treatment for an inbound webhook.

**`PostInvoiceOrchestrator` is exemplary code.** It is worth reading as a reference for the whole portfolio:

```
PHASE 1 — atomic core (inside DB::transaction)
   lockForUpdate()  →  credit-limit gate  →  journal entry
   →  inventory deduction  →  status flip to SENT
PHASE 2 — domain event, dispatched only after commit
PHASE 3 — non-transactional side effects
   ZATCA submission · customer notification · rebate accrual
   · event tracking · queued PDF generation
```

Pessimistic locking to serialise concurrent sends; `bcadd`/`bccomp` for credit arithmetic; external calls strictly after commit; non-critical side effects individually try/caught so a rebate failure cannot roll back a posted invoice. This is production-grade financial code.

### 3.2 The central architectural weakness — Rule 2 is 1/25th adopted

**There is exactly one orchestrator** (`app/Orchestrators/Sales/PostInvoiceOrchestrator.php`) for a system with 25+ modules, 426 services and explicitly-declared bounded contexts (`Accounting`, `Sales`, `Purchase`, `Inventory`, `HR`, `Manufacturing`, `Projects`, `Compliance`).

The rule's own text is candid: *"Other cross-module flows still call services directly and should move to an orchestrator when next touched."*

**Impact.** Every cross-module flow other than invoice posting — goods receipt updating inventory *and* accounting, payroll posting to the ledger, work-order completion moving stock *and* costs, purchase-order receipt triggering three-way match — currently runs as direct service-to-service calls. Those are precisely the flows where a partial commit corrupts financial data, and precisely what Rule 2 exists to prevent. The rule is correct; its adoption is the gap.

| | |
|---|---|
| **Severity** | 🟠 High |
| **Complexity** | **L** — roughly 8–12 orchestrators for the major flows |
| **Migration impact** | Internal; incremental, one flow at a time |
| **Trade-off** | Each orchestrator adds a class and an indirection. Worth it only for genuinely cross-module flows — do **not** create orchestrators for single-module operations. |

**Recommendation.** Rank cross-module flows by financial blast radius and convert the top eight. Start with: goods receipt, payroll posting, work-order completion, payment application, stock transfer between branches, purchase invoice three-way match, credit note reversal, period close.

### 3.3 Event-driven architecture is thinner than the service count suggests

18 events and 18 listeners against 426 services. The events that exist are well-built (idempotency guards, CONTRACT docblocks per Rule 4), but the ratio shows most inter-module communication is still synchronous and direct. This is the same finding as §3.2 from a different angle: the *patterns* are right, the *coverage* is early.

This is not necessarily wrong — a modular monolith with direct calls is simpler than premature event choreography, and [01 §5.3](01-DISCOVERY-AND-ARCHITECTURE.md#53-anti-pattern-speculative-resilience-infrastructure) warns against building machinery ahead of need. The concern is specifically that **cross-module writes** lack transactional ownership, not that events are under-used generally.

---

## 4. `erp-backend` — security findings

Severity definitions as in [02-SECURITY-AUDIT](02-SECURITY-AUDIT.md#severity-definitions).

### 🟠 EB-H-1 — The tenant global scope silently disengages outside HTTP requests

**Status:** Confirmed
**Evidence:** `app/Models/Concerns/BelongsToOrganization.php`

```php
static::addGlobalScope('organization', function (Builder $builder): void {
    if ($user = auth()->user()) {                    // ← no user ⇒ NO SCOPE APPLIED
        $table = $builder->getModel()->getTable();
        $builder->where("{$table}.organization_id", $user->organization_id);
    }
});
```

In every non-HTTP execution context — the 18 queued jobs, all console commands, the scheduler, and queued event listeners — `auth()->user()` is `null`. The closure then applies **no filter at all**, and the query returns **every tenant's rows**.

The `creating` hook does throw `MissingTenantException` when `organization_id` is absent, so *writes* are guarded. **Reads are not.** A cross-tenant read is the more likely and more damaging failure: it silently feeds another tenant's data into a report, a snapshot, a segment, or a notification.

**Mitigating fact, verified.** The existing jobs are defensively written — `UpdateStockLevelSnapshotJob` takes `organizationId` as a constructor argument and filters explicitly (`Product::where('organization_id', $this->organizationId)`), and `ExecuteScheduledReportJob` scopes from `$this->report->organization_id`. **Nothing enforces this.** It is the same "discipline rather than structure" weakness identified as [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) in Masaar, one level less severe because the convention is currently being followed.

**Remediation.** Make the scope fail closed rather than open:

```php
static::addGlobalScope('organization', function (Builder $builder): void {
    $tenantId = app(TenantContext::class)->id();   // auth user OR explicitly-set job context
    if ($tenantId === null) {
        throw MissingTenantException::forQuery($builder->getModel()::class);
    }
    $builder->where($builder->getModel()->getTable().'.organization_id', $tenantId);
});
```

Introduce an explicit `TenantContext` that jobs set (`TenantContext::run($orgId, fn () => …)`), and keep `withoutOrganizationScope()` as the audited, logged escape hatch that already exists. Roll out behind a config flag, fix the resulting exceptions, then enforce.

| | |
|---|---|
| **Complexity** | **M** | **Migration impact** | Every job and command must declare its tenant; expect a noisy first pass |

---

### 🟠 EB-H-2 — 501 models carry `organization_id` without the tenancy trait

**Status:** Confirmed
**Evidence:** 575 of 1,076 model files use `BelongsToOrganization`. Of the remainder, many declare `organization_id` but omit the trait — therefore no global scope and no write guard.

A sample of models in this category:

| Clearly tenant data — 🟠 should have the trait | Plausibly platform-level — ⚪ verify |
|---|---|
| `Accounting/BankTransaction` | `Admin/FeatureFlag` |
| `Accounting/CreditExposure` | `Admin/SystemAnnouncement` |
| `Accounting/TreasuryInvestment` | `Admin/PlatformAdminActivity` |
| `Accounting/AccountBalanceSnapshot` | `Analytics/Dim*` (warehouse dimensions) |
| `Accounting/CashFlowForecast`, `LiquidityPlan` | `Admin/OrganizationStatusHistory` |
| `Aml/AmlSuspiciousActivity`, `AmlRiskScore`, `AmlCddRecord` | |
| `Admin/SupportTicket` | |

Bank transactions, credit exposure, treasury positions and AML suspicious-activity records are among the **most sensitive data in the system**. Their queries are currently unscoped by default.

**Remediation.** Audit all 501. For each: apply the trait, or add an explicit `// PLATFORM-LEVEL: intentionally not tenant-scoped — <reason>` annotation. Then add an architectural test that fails the build when a model has an `organization_id` column, lacks the trait, and lacks the annotation. This converts a 501-item manual review into a permanently enforced invariant.

| | |
|---|---|
| **Complexity** | **M** (mostly mechanical) | **Migration impact** | Low risk per model; do in batches by domain |

---

### 🟡 EB-M-1 — `forceState()` and `withoutTenantCheck()` are logged but not restricted

Both bypasses log a warning with caller and user context — good design. Neither is restricted to permitted contexts. Rule 6 says `forceState()` is "permitted only in migrations and admin tooling," but nothing enforces that; any service can call it and silently defeat the state machine.

**Remediation.** Add an architectural test asserting `forceState(` appears only under `database/`, `app/Console/`, and designated admin services. Same for `withoutTenantCheck(`.

### 🟡 EB-M-2 — Test coverage is thin relative to surface area

185 test files / ~2,140 test methods against 2,100 source files and 3,155 routes. Materially better than Masaar, but for a system posting journal entries and running payroll, the ratio is low. Priority gaps: the 501 unscoped models (EB-H-2), cross-module flows lacking orchestrators (§3.2), and the bypass paths (EB-M-1).

### 🟡 EB-M-3 — Duplicate ZATCA implementation

`app/Services/Compliance/ZatcaClientV1.php` (46 lines) and `ZatcaInvoiceTransformer.php` (159 lines) duplicate compliance knowledge that belongs solely to Masaar. Already recorded as [01 §1.1](01-DISCOVERY-AND-ARCHITECTURE.md#11-the-duplication-problem) and roadmapped in [08-CLEANUP-PLAN](08-CLEANUP-PLAN.md).

### ✅ Controls correctly implemented

Layered middleware chain with module and permission gating · JWT validation separated from authentication · global security headers · HMAC-verified inbound webhook outside auth · dense per-route permission checks · `MasksSensitiveData` and `LogsExternalApiCalls` traits · IP allowlist service · optimistic locking trait · `SensitiveAccessService` · GDPR service · `TenantRateLimitService` (per-tenant limits — notably better than Masaar's per-user limiter, [M-3](02-SECURITY-AUDIT.md#3-medium-findings)).

---

## 5. `erp-frontend` — assessment

### 5.1 The finding

| Package | Source files | Assessment |
|---|:---:|---|
| `apps/staff` | **29** | Internal staff portal — ZATCA, invoicing, accounting, HR, sales… |
| `apps/admin` | **5** | Super-admin console |
| `apps/portal` | **4** | Vendor self-service portal |
| `packages/ui` | 40 | Design system — **the most developed part of the repository** |
| `packages/api-client` | 6 | Axios + TanStack Query hooks |
| `packages/types` | 5 | Shared TypeScript interfaces |
| **Test files** | **10** | Across the entire monorepo |

**38 application source files must front 3,155 backend routes across 25+ ERP modules.** The arithmetic is decisive: this is not an application with gaps, it is a foundation with three placeholder apps on top.

The README describes the design system in confident detail — token source, preset file, component inventory, the "never duplicate `@theme` blocks" rule, curated Lucide re-exports. That documentation is accurate and the design system is real. The applications are not.

### 5.2 What this means strategically

The portfolio has **a world-class backend domain model with almost no way for a human to use it.** Every one of those 3,155 endpoints is currently reachable only by API client. For an ERP — a category defined by the breadth of its screens — that is the single largest product gap in the portfolio, larger than anything in Masaar.

Two coherent responses exist, and they are mutually exclusive:

| Option | Implication | Complexity |
|---|---|---|
| **A — Commit to the frontend.** Build the ERP UI properly. | 25+ modules × ~6 screens ≈ 150+ screens. This is the dominant cost in the portfolio. Requires schema-driven generation (see below) to be tractable. | **XL** — 12+ months |
| **B — Reposition as an API-first / headless ERP.** The backend is the product; customers build or buy their own UI; ship only admin + a thin ops console. | Plays to the actual asset. Dramatically smaller surface. Consistent with Masaar's API-first positioning ([04 §5.3](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#53-positioning)). | **L** |

**Recommendation: decide this explicitly, and soon.** Continuing to accrete backend services while the frontend stays at 38 files is a third option that is being chosen by default rather than deliberately, and it is the worst of the three. This is a business decision, not a technical one — I have flagged it in [08 §5](08-CLEANUP-PLAN.md#5-the-one-decision-that-must-be-made-by-a-human) rather than resolving it.

If **A**, the only tractable route is generation: the backend already has 387 controllers with FormRequests and API Resources. Generate an OpenAPI spec from them, then generate typed clients and CRUD scaffolding into `apps/staff`. Hand-building 150 screens is not viable at this team size.

### 5.3 Other frontend observations

- **The monorepo tooling is correct** — Turborepo, pnpm workspaces, shared `tsconfig`, a `typecheck` and `lint` pipeline. The infrastructure is ready for far more code than it holds.
- **`packages/api-client` (6 files) is the integration seam** and should be generated from the backend's OpenAPI spec rather than hand-written, for the same drift reasons as [04 §1.4](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#14-the-documentation-principle-to-adopt).
- **10 test files.** An `e2e` directory exists under `apps/staff`. With so little application code the low count is unsurprising, but the testing discipline should be established *now*, while the surface is small, rather than retrofitted at 150 screens.
- **No security findings** — there is not enough application code to have any. This is not reassurance; it is an absence of surface.

---

## 6. Cross-repository consistency

Three repositories, three different conventions for the same concerns.

| Concern | Masaar | erp-backend | erp-frontend |
|---|---|---|---|
| **Tenancy** | Manual `where()`; dead guard class ([C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth)) | `BelongsToOrganization` trait + global scope | n/a |
| **Response envelope** | 3 competing shapes ([03 §5.1](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#51-observed-inconsistencies)) | 1 documented envelope with `meta.request_id` | Consumes backend's |
| **Auth** | JWT **and** licence key+secret, two stacks | JWT only, one chain | JWT |
| **Rate limiting** | Per-user | **Per-tenant** (`TenantRateLimitService`) | n/a |
| **Idempotency** | `SubmissionIdempotency` table (compliance-specific) | `FinancialIdempotencyService` (general, DB-constraint-based) | n/a |
| **State machines** | Enum + ad-hoc `isEditable()` | `HasStateMachine` trait, 15 models | n/a |
| **Architecture rules doc** | ❌ none | ✅ 8 rules with enforcement markers | ❌ none |
| **README honesty** | ❌ Overclaims | ✅ Understates | ✅ Accurate |

**In every row where the two backends differ, `erp-backend`'s approach is the better one.** The portfolio's most valuable near-term action is therefore not to invent new standards but to **propagate erp-backend's existing ones to Masaar** — see [08-CLEANUP-PLAN §2](08-CLEANUP-PLAN.md#2-track-a--propagate-erp-backends-standards-to-masaar).

---

## 7. Recommendations

| # | Recommendation | Repo | Severity / Value | Complexity | Migration impact |
|---|---|---|---|:---:|---|
| EB-1 | Make the tenant scope fail closed; add explicit `TenantContext` for jobs | erp-backend | 🟠 High | **M** | Noisy first pass; every job declares its tenant |
| EB-2 | Audit 501 untraited models; add an architectural test to enforce | erp-backend | 🟠 High | **M** | Mechanical; batch by domain |
| EB-3 | Convert the top 8 cross-module flows to orchestrators (Rule 2) | erp-backend | 🟠 High | **L** | Incremental, one flow at a time |
| EB-4 | Restrict `forceState()` / `withoutTenantCheck()` by architectural test | erp-backend | 🟡 Medium | **S** | None |
| EB-5 | Generate OpenAPI from controllers + FormRequests | erp-backend | 🟠 High | **M** | None — additive |
| EB-6 | Delete `ZatcaClientV1`; Masaar owns all ZATCA knowledge | erp-backend | 🟠 High | **S** | Cross-repo; coordinate release |
| EB-7 | Raise coverage on unscoped models, bypass paths, cross-module flows | erp-backend | 🟡 Medium | **M** | None |
| **FE-1** | **Decide Option A vs Option B (§5.2)** | erp-frontend | 🔴 **Blocking** | — | **Determines the portfolio's largest cost** |
| FE-2 | Generate `packages/api-client` from the backend spec | erp-frontend | 🟠 High | **S** | Depends on EB-5 |
| FE-3 | Establish testing discipline now, at 38 files | erp-frontend | 🟡 Medium | **S** | None |
| **X-1** | **Adopt `docs/architecture-rules.md` portfolio-wide** | all | 🟢 **Highest value / lowest cost** | **S** | None — documentation |
| X-2 | Extract shared primitives into a common package | all | 🟠 High | **M** | See [07 §5](07-KNOWLEDGE-EXTRACTION.md#5-suggested-independent-packages) |

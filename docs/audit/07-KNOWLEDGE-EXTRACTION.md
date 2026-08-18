# Knowledge Mining & Reusable Asset Extraction

**Audit date:** 2026-08-18 · **Method:** read-only static investigation
**Related:** [06-PORTFOLIO-AUDIT-ERP](06-PORTFOLIO-AUDIT-ERP.md) · [08-CLEANUP-PLAN](08-CLEANUP-PLAN.md)

> **Purpose.** Identify business knowledge, engineering patterns and reusable components that have accumulated across the three repositories and would be lost or diluted in a modernisation. This is an inventory of intellectual property, not a refactoring plan.
>
> **Scope caveat.** `erp-backend` holds 426 services across 25+ domains. This pass sampled the architectural layer, the platform primitives, and representative services from Accounting, Tax, Sales, Manufacturing and Inventory. **The domain-rule catalogues in §3 are structural findings and named inventories, not an exhaustive line-by-line rule extraction** — that requires domain-expert review per module and is scoped in §9.

---

## 1. Executive summary

The portfolio contains three distinct classes of asset, with very different reuse profiles.

| Class | Where | Reuse potential | Assessment |
|---|---|:---:|---|
| **Platform primitives** — tenancy, state machines, idempotency, approvals, numbering, webhooks | `erp-backend/app/Models/Concerns/`, `app/Services/Core/` | **9–10/10** | Framework-agnostic in concept, immediately reusable in Laravel, directly transplantable to Masaar today |
| **Regulated-domain engines** — VAT/GST/TDS/Zakat, ZATCA/FTA compliance, withholding tax | `erp-backend/app/Services/Tax`, `Compliance`; all of `Masaar` | **8–9/10** | The highest-value, hardest-to-rebuild IP. Encodes regulation, not code. |
| **ERP domain depth** — accounting, MRP, costing, payroll, WMS | `erp-backend/app/Services/{Accounting,Manufacturing,Inventory,HR}` | **6–8/10** | SAP-parity breadth. Enormous replacement cost; reusable as a product, less so as libraries. |

**The single most valuable finding:** `erp-backend` already contains, tested and in production shape, the exact primitives Masaar is missing — a tenancy trait that fails on unscoped writes, a state-machine trait, and a DB-constraint-based idempotency service. **Masaar's [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) can be closed by transplanting existing code rather than writing new code.** That is the cheapest security fix available anywhere in the portfolio.

---

## 2. Hidden gems

Implementations worth preserving verbatim, in priority order.

### 💎 G-1 — `VatRuleResolver` + `VersionedRule`: temporal rule versioning

`erp-backend/app/Services/Tax/VatRuleResolver.php` · Reusability **10/10** · Complexity **2/10**

```php
/**
 * Resolves the correct VAT rule version for a given transaction date.
 * Old invoices use the rule that was active when they were created.
 */
public function resolveForDate(\DateTimeInterface|string $date): VersionedRule
```

Fewer than 60 lines. It solves a problem that breaks most compliance systems: **when a tax rate or rule changes, historical documents must continue to be calculated, re-printed and audited under the rule in force at their transaction date** — not today's rule. Systems that store only the current rate silently corrupt their own history and fail audits.

The pattern generalises far beyond tax: pricing rules, commission schedules, SLA terms, interest calculations, insurance schedules, regulatory limits — **any rule that changes over time while historical records must remain reproducible.**

**Why it is a gem:** the cost of *discovering* this requirement is high (usually via a failed audit); the cost of *implementing* it once known is trivial. The knowledge is the asset.

**Recommendation:** extract as `VersionedRuleResolver<T>` into the shared package (§5). Adopt in Masaar immediately — ZATCA has revised its specification repeatedly, and the `rule_version` column already exists on Masaar's invoices ([01 §6](01-DISCOVERY-AND-ARCHITECTURE.md#6-configuration-data-and-deployment)) with no resolver behind it.

---

### 💎 G-2 — `FinancialIdempotencyService`: the database as the serialisation point

`erp-backend/app/Services/Core/FinancialIdempotencyService.php` · Reusability **10/10** · Complexity **4/10**

> *"Uses a unique-constraint INSERT as the serialisation point — the database, not application code, guarantees exactly-once semantics under concurrency."*

Correctly handles every state: first writer inserts `processing`; duplicates within TTL replay the cached result; concurrent callers receive HTTP 409; failures delete the row to permit retry; stale `processing` rows time out; and it handles the row-disappeared race with a bounded recursive retry.

Critically, its docblock **distinguishes itself from the HTTP-layer `IdempotencyService`** — two idempotency mechanisms at different layers, with the difference documented. That distinction is exactly what is usually missing and is why teams end up with one confused half-working mechanism.

**Why it is a gem:** most idempotency implementations use a cache check-then-set, which is not atomic and fails under concurrency precisely when it matters — during a retry storm.

**Recommendation:** extract as a standalone package. Masaar's `SubmissionIdempotency` should be re-based on it.

---

### 💎 G-3 — `PostInvoiceOrchestrator`: the three-phase transaction pattern

`erp-backend/app/Orchestrators/Sales/PostInvoiceOrchestrator.php` · Reusability **9/10** · Complexity **5/10**

```
PHASE 1  atomic core inside DB::transaction — lockForUpdate, credit gate,
         journal entry, inventory deduction, status flip
PHASE 2  domain event, dispatched only after commit
PHASE 3  non-transactional side effects, each independently try/caught
```

Pessimistic locking serialises concurrent sends; `bcadd`/`bccomp` for credit arithmetic; external calls strictly post-commit; a failing rebate accrual cannot roll back a posted invoice. The class docblock states its preconditions explicitly (*"the invoice is in STATUS_DRAFT, and execute() runs at most once per invoice"*) and names who enforces them.

**Why it is a gem:** this is the correct answer to "how do I write a business transaction with side effects" — a question most codebases answer wrongly by putting HTTP calls inside `DB::transaction`. It is also the reference implementation for Rule 2, which is only 1/25th adopted ([06 §3.2](06-PORTFOLIO-AUDIT-ERP.md#32-the-central-architectural-weakness--rule-2-is-125th-adopted)).

**Recommendation:** promote to a documented template. Extract the shape as an abstract `Orchestrator` base class with `atomicCore()`, `domainEvent()`, `sideEffects()` hooks so the phase discipline is structural rather than remembered.

---

### 💎 G-4 — `docs/architecture-rules.md`: enforcement-marked architectural rules

`erp-backend/docs/architecture-rules.md` · Reusability **10/10** · Complexity **1/10**

Eight rules, each with *Rule / Why / Pattern (✅ and ❌ examples) / Enforcement / Current adoption*, and an honest `[ENFORCED]` / `[PARTIALLY ADOPTED]` / `[ASPIRATIONAL]` marker. Plus a violation-response table mapping severity to action.

**Why it is a gem:** the honesty markers are the innovation. Most architecture documents describe an aspirational system and quietly become fiction. Marking a rule `[PARTIALLY ADOPTED]` and naming the single class that implements it — as Rule 2 does — keeps the document trustworthy, which is the only property that makes it useful.

**Recommendation:** **this is the highest-value, lowest-cost action in the entire portfolio.** Copy the format to Masaar and erp-frontend this week.

---

### 💎 G-5 — `BelongsToOrganization`: tenancy that fails closed on write

`erp-backend/app/Models/Concerns/BelongsToOrganization.php` · Reusability **9/10** · Complexity **3/10**

Global read scope, auto-set `organization_id` on create, **hard failure via `MissingTenantException` if still missing**, and a `withoutTenantCheck(callable, string $reason)` bypass that *requires* a reason and logs caller + user in production and staging.

**Why it is a gem:** the mandatory-reason bypass. Most bypasses are a boolean flag that becomes invisible; requiring a string and logging it makes every bypass auditable and socially expensive.

**Known limitation:** the read scope silently disengages when there is no authenticated user — see [06 EB-H-1](06-PORTFOLIO-AUDIT-ERP.md#-eb-h-1--the-tenant-global-scope-silently-disengages-outside-http-requests). **Fix that before extracting**, otherwise the defect propagates to Masaar along with the benefit.

---

### 💎 G-6 — `HasStateMachine`: guarded transitions with lifecycle hooks

`erp-backend/app/Models/Concerns/HasStateMachine.php` · Reusability **9/10** · Complexity **3/10**

Declarative transition maps; `transitionTo()` throws `InvalidStateTransitionException` (HTTP 422) listing the allowed moves; convention-based `onBefore{State}` / `onAfter{State}` hooks; the whole transition wrapped in a transaction; `isInTerminalState()` derived from an empty transition list; a query scope; and a `forceState()` escape hatch that logs model, id, from, to and user.

Applied to 15 models — Invoice, SalesOrder, Quotation, PurchaseOrder, Bill, PaymentReceived, PaymentMade, LeaveRequest, InterCompanyTransfer, StockAdjustment, StockTransfer, Payslip, PayrollPeriod, PaymentRun, WorkOrder — and the documentation's list matches the code exactly.

**Recommendation:** extract. Masaar's invoice lifecycle currently relies on an ad-hoc `isEditable()` check and would benefit directly.

---

### 💎 G-7 — Bank-statement parsers: `Mt940Parser`, `Camt053Parser`, `EbsParserService`

`erp-backend/app/Services/Accounting/` · Reusability **9/10** · Complexity **7/10**

MT940 and CAMT.053 are banking interchange formats with fragmented per-bank dialects. A working parser is weeks of undocumented edge cases and is valuable to **any** business application that reconciles bank statements, in any industry.

**Recommendation:** strong standalone open-source package candidate. High community value, no competitive disclosure — parsing a public banking format reveals nothing proprietary.

---

### 💎 Also worth preserving

| Asset | Location | Why |
|---|---|---|
| `NumberGeneratorService` | `Services/Core` | Pattern-based sequential document numbering (`{prefix}-{year}-{number}`), org-scoped, transaction-wrapped, with a non-incrementing `preview`. Every business application needs this; almost every one re-implements it badly with a race condition. |
| `ApprovalWorkflowService` + `WorkflowEscalationService` | `Services/Core` | Polymorphic (`approvable_type`/`approvable_id`) multi-step approvals with delegation, amount-based routing and escalation. Applies to any document type in any domain. |
| `HasOptimisticLocking` | `Models/Concerns` | Lost-update prevention — rare to find implemented at all. |
| `DispatchesWebhooks` | `Models/Concerns` | Model-lifecycle-driven emission, so no write path can skip it; subscription check before recording, so organisations without webhooks pay one indexed query. The cost analysis is documented in Rule 8. |
| `MasksSensitiveData`, `LogsExternalApiCalls`, `StructuredLogger` | `app/Traits` | Observability primitives that solve the [L-4](02-SECURITY-AUDIT.md#4-low-findings) log-sanitisation gap in Masaar. |
| `TenantRateLimitService` | `Services/Core` | Per-tenant limiting — directly fixes Masaar's [M-3](02-SECURITY-AUDIT.md#3-medium-findings). |
| ZATCA pipeline (ICV/PIH chain, TLV QR, XAdES) | `Masaar/Domains/Compliance/Fatoora` | The regulated core; see [01 §4.2](01-DISCOVERY-AND-ARCHITECTURE.md#42-submission-pipeline-saudi-arabia--fatoora). |
| `ComplianceEngine` / `ComplianceRouter` | `Masaar/Domains/Compliance` | Multi-jurisdiction strategy abstraction — the portfolio's best single design decision. |

---

## 3. Business knowledge inventory

### 3.1 Confirmed business rules

Rules verified directly in code during this pass:

| Rule | Where | Significance |
|---|---|---|
| Historical documents calculate under the rule version active at their transaction date | `VatRuleResolver` | Audit reproducibility |
| An invoice may not be sent if it breaches the customer's credit limit *at confirmation time*, re-checked because exposure may have changed since creation | `PostInvoiceOrchestrator::assertCreditLimit()` | Explicitly commented — a hard-won distinction |
| Breaching a credit limit **automatically creates a `CreditHold`**, it does not merely reject | same | Rejection alone would let the next invoice through |
| A `BLOCKED` credit risk class creates a hold and refuses, independent of amount | same | |
| Credit arithmetic uses `bcadd`/`bccomp`, never floats | same | |
| Invoice lifecycle: `draft → sent → {partial, paid, overdue, voided}`; `paid` and `voided` are terminal; `draft` may never reach `paid` directly | `Invoice::getStateTransitions()` | 15 such maps exist |
| B2B (standard) invoices must be ZATCA-**cleared** before the customer is notified; simplified invoices notify immediately | `PostInvoiceOrchestrator::notifyCustomer()` | Regulatory sequencing embedded in code |
| A ZATCA connection failure sets `compliance_pending` and schedules a 5-minute retry; a rejection records the reason and continues; other exceptions roll the invoice to `compliance_rejected` and rethrow | same | Three distinct failure semantics |
| Rebate accrual and event-tracking failures must never fail invoice posting | same | Side effects individually try/caught |
| Queued listeners must check whether their effect was already applied before applying it | Rule 4 + 18 listeners | Retry safety |
| Financial writes are exactly-once within a 24-hour TTL, serialised by a DB unique constraint | `FinancialIdempotencyService` | |
| A tenant-scoped model may not be persisted without `organization_id` | `BelongsToOrganization` | |
| Invoices are immutable after issuance | `Masaar/InvoiceController` | Compliance requirement |
| Tax computation uses `bcmath`, never floats | `Masaar/InvoiceController::store()` | |
| Every invoice's ICV increments atomically and its PIH links to the previous invoice's hash | `Masaar/AtomicIcvManager`, `HashChainManager` | ZATCA-mandated |

### 3.2 Domain areas with rules **not yet extracted**

Named honestly: these are areas where rules certainly exist in code but were **not** enumerated in this pass. Each needs domain-expert review.

| Domain | Services | Rule areas likely embedded |
|---|:---:|---|
| Accounting | 71 | Document splitting, parallel ledgers, COPA allocation, assessment/distribution cycles, material ledger, period locks, dunning escalation, payment tolerance, GR/IR clearing, transfer pricing, consolidation elimination, Zakat basis |
| HR | 46 | Leave accrual and carry-forward, GOSI contribution bands, EOSB gratuity formulas, payroll proration, attendance rounding |
| Manufacturing | 37 | MRP netting, lot sizing, capacity levelling, BOM explosion, backflushing, scrap and yield, standard-cost release, variance categorisation |
| Sales | 35 | Pricing condition hierarchy, ATP checks, rebate accrual, promotion stacking, intercompany billing |
| Inventory | 25 | Valuation methods, split valuation, batch determination, shelf-life, reorder point, wave picking, storage-type determination |
| Purchase | 22 | Three-way match tolerance, release strategy, vendor evaluation |
| Tax | 15 | GST place-of-supply, TDS/TCS thresholds and rates, e-way bill triggers, IRN generation, VAT return mapping |

**Estimated effort for full extraction:** 2–3 weeks with domain-expert pairing, producing a rules catalogue per module. This is the highest-value documentation work available and is scoped in §9.

### 3.3 Domain glossary — start here

Terminology confirmed in use, requiring a canonical definition per term: `organization` (tenant) · `branch` (EGS unit in ZATCA terms) · ICV / PIH / CSID / CCSID / PCSID · clearance vs reporting · standard vs simplified invoice · credit exposure / credit hold / credit limit / risk class · COPA · document splitting · parallel ledger · GR/IR · release strategy · ATP · BOM explosion · backflushing · split valuation · EOSB · GOSI · IRN · e-way bill · Zakat basis.

**Recommendation:** a single `docs/GLOSSARY.md` shared across all three repositories. Cheap, and it is the artefact new engineers and AI assistants need most.

---

## 4. Engineering pattern catalogue

Patterns confirmed present, with their implementation quality.

| Pattern | Implementation | Quality |
|---|---|---|
| **Service Layer** | 426 services; Rule 1 enforces controllers delegate | 🟢 Strong |
| **Orchestrator / Transaction Script** | `PostInvoiceOrchestrator` | 🟢 Excellent — 🔴 1/25th adopted |
| **State Machine** | `HasStateMachine`, 15 models | 🟢 Strong |
| **Strategy** | `ComplianceEngine` (Masaar), `VersionedRule` | 🟢 Strong |
| **Factory** | `JournalEntryFactory` | 🟢 Good |
| **Global Scope / Multi-tenancy** | `BelongsToOrganization` | 🟡 Good, fails open on read |
| **Domain Events + Listeners** | 18/18, idempotent by contract | 🟡 Correct but sparse |
| **Idempotency (two layers)** | `FinancialIdempotencyService` + HTTP `IdempotencyService` | 🟢 Excellent |
| **Optimistic Locking** | `HasOptimisticLocking` | 🟢 Rare and valuable |
| **Pessimistic Locking** | `lockForUpdate()` in orchestrator | 🟢 Correct |
| **Observer** | `DispatchesWebhooks`, `HasAuditTrail` | 🟢 Good |
| **Polymorphic Workflow** | `ApprovalWorkflowService` | 🟢 Good |
| **Rules Engine** | `FraudRuleEngine` + `FraudRuleTemplates`, `AutomationRuleService`, `TaxDeterminationService` | 🟡 Three separate engines — consolidation candidate |
| **Feature Flags** | `FeatureFlagService` | 🟢 Good |
| **Circuit Breaker** | `Services/Compliance/CircuitBreaker` (ERP) + two in Masaar | 🔴 Three implementations |
| **DTO / Value Object** | `app/DTOs/{Module}`, Masaar `Domains/*/DTOs` | 🟢 Good |
| **Repository** | Largely absent — Eloquent used directly | ⚪ Acceptable choice |
| **CQRS** | `app/Commands/` + `app/Actions/` directories exist | ⚪ Partial; intent unclear |
| **Saga / Compensation** | Not present | 🔴 Gap for multi-step financial flows |

**Anti-pattern observed portfolio-wide:** the same concern solved three times in three repositories — circuit breakers (3), idempotency (3 mechanisms), rate limiting (2 models), tenancy (2 approaches). Each was reasonable locally; collectively they are the strongest argument for the shared package in §5.

---

## 5. Suggested independent packages

Extraction candidates, ranked by value ÷ effort.

| # | Package | Contents | Reuse | Cplx | Effort | Audience |
|:---:|---|---|:---:|:---:|:---:|---|
| **P1** | `platform/tenancy` | `BelongsToOrganization` (fixed per EB-H-1), `TenantContext`, `MissingTenantException`, architectural tests | 10 | 3 | **S** | Internal — **do first** |
| **P2** | `platform/idempotency` | `FinancialIdempotencyService`, `ChecksIdempotency`, HTTP-layer service, migration | 10 | 4 | **S** | Internal, then OSS |
| **P3** | `platform/state-machine` | `HasStateMachine`, `InvalidStateTransitionException` | 9 | 3 | **S** | Internal, then OSS |
| **P4** | `platform/versioned-rules` | `VersionedRule`, `VersionedRuleResolver<T>` | 10 | 2 | **S** | **OSS — genuinely novel** |
| **P5** | `platform/observability` | `StructuredLogger`, `MasksSensitiveData`, `LogsExternalApiCalls` | 9 | 3 | **S** | Internal |
| **P6** | `platform/numbering` | `NumberGeneratorService` | 9 | 3 | **S** | OSS |
| **P7** | `platform/approvals` | Approval workflow + escalation + delegation, polymorphic | 8 | 6 | **M** | Internal, then commercial |
| **P8** | `banking/statement-parsers` | MT940, CAMT.053, EBS | 9 | 7 | **M** | **OSS — high community value** |
| **P9** | `gcc/tax-engine` | VAT (6 GCC states), Zakat, withholding, GST/TDS/TCS | 9 | 8 | **L** | **Commercial — core IP** |
| **P10** | `gcc/e-invoicing` | Masaar's compliance engines | 9 | 9 | **L** | **Commercial — the product** |
| **P11** | `platform/webhooks` | `DispatchesWebhooks`, delivery, DLQ, retry, subscriptions | 8 | 6 | **M** | Internal, then OSS |
| **P12** | `platform/orchestrator` | Abstract three-phase base class + docs | 8 | 3 | **S** | Internal |

**Sequencing.** P1–P6 and P12 are all **S**-complexity, mostly move-and-namespace work, and together they eliminate most cross-repository duplication. They should be a single ~3-week effort. **P1 is the priority: it closes Masaar's [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) with code that already exists and is in production.**

**A caution on extraction.** Premature packaging adds versioning and release overhead. Extract only what is genuinely used by **two or more** repositories, and start as a private monorepo package rather than a published one. P9/P10 should not be extracted at all until the product strategy in [04 §5](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#5-product-strategy) is settled.

---

## 6. Cross-project reusability matrix

| Asset | Masaar | erp-backend | erp-frontend | Future | Language-portable? |
|---|:---:|:---:|:---:|:---:|---|
| Tenancy trait | 🔴 **needs it now** | ✅ has | — | ✅ | Concept yes; code is Eloquent-specific |
| Idempotency service | 🟡 partial | ✅ has | — | ✅ | **Yes** — pure DB-constraint pattern |
| State machine | 🔴 needs | ✅ has | — | ✅ | **Yes** — declarative maps |
| Versioned rules | 🔴 **needs** (`rule_version` column exists, no resolver) | ✅ has | — | ✅ | **Yes** — pure logic |
| Structured logging / masking | 🔴 needs ([L-4](02-SECURITY-AUDIT.md#4-low-findings)) | ✅ has | — | ✅ | Concept yes |
| Per-tenant rate limiting | 🔴 needs ([M-3](02-SECURITY-AUDIT.md#3-medium-findings)) | ✅ has | — | ✅ | Concept yes |
| Approval workflow | ⚪ n/a | ✅ has | 🟡 needs UI | ✅ | **Yes** — data-driven |
| Number generator | 🟡 could use | ✅ has | — | ✅ | **Yes** |
| Architecture rules doc | 🔴 **needs** | ✅ has | 🔴 needs | ✅ | **Yes** — prose |
| Compliance engines | ✅ owns | 🔴 **duplicates — delete** | — | ✅ | Spec is portable; code is not |
| Bank parsers | ⚪ n/a | ✅ has | — | ✅ | **Yes** — format parsing |
| Design system | ⚪ n/a | — | ✅ has | ✅ | Web only |

**Reading the first column:** Masaar needs six primitives that erp-backend already has, and erp-backend holds one thing it should not (the duplicate ZATCA client). The two repositories are close to complementary — a small number of moves in each direction resolves most of the portfolio's duplication.

---

## 7. Language- and framework-agnostic specifications

For assets marked portable, the durable artefact is **the specification, not the PHP**. Recommended form:

| Asset | Portable artefact |
|---|---|
| State machines | Transition maps as JSON/YAML per entity — consumable by any language |
| Versioned rules | Rule definitions with `effective_from`, `version`, and a pure calculation spec |
| Tax rules | Decision tables (jurisdiction × transaction type × date → rate, treatment, reporting box) |
| Idempotency | A one-page protocol spec: table schema, states, TTL, conflict semantics |
| Approval workflows | Workflow definitions as data — steps, conditions, amount thresholds, delegation |
| E-invoicing | Already specified by ZATCA/FTA; Masaar's value is the *implementation* plus the error-explanation knowledge |
| Business rules catalogue | Structured markdown per module — the input to any future reimplementation |

**Writing these down is what makes the IP survive a rewrite, a language change, or the departure of the engineer who wrote it.** It also directly addresses risk [R-14](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#6-risk-register) (key-person dependency on domain knowledge) and, as a side benefit, is the single most useful thing that can be done to make the codebase legible to AI assistants.

---

## 8. Product opportunities

Assets that could stand alone commercially, with honest caveats.

| Opportunity | Basis | Value | Realism |
|---|---|---|---|
| **GCC e-invoicing platform** | Masaar | 🟢 High | Committed direction; see [04 §5](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#5-product-strategy) |
| **GCC + India tax engine as a service** | 15 tax services, `VatRuleResolver` | 🟢 High | Natural adjacency to Masaar; same buyer, same channel |
| **Headless ERP API** | 3,155 routes, 426 services | 🟡 Medium–High | Depends on the [06 FE-1](06-PORTFOLIO-AUDIT-ERP.md#52-what-this-means-strategically) decision |
| **Bank-statement parsing library** | MT940/CAMT.053/EBS | 🟡 Medium | Best as OSS for credibility, not revenue |
| **Approval-workflow service** | `ApprovalWorkflowService` | 🟡 Medium | Crowded market |
| **Laravel enterprise starter kit** | P1–P6 + P12 + architecture rules | 🟡 Medium | Strong OSS reputation play; low direct revenue |

**A necessary caution.** Three products cannot be built at once by one team. The portfolio's current state — a compliance platform with four unauthenticated endpoints, an ERP with one orchestrator, and a frontend with 38 files — is already the result of breadth exceeding capacity. **Treat §8 as an inventory of options, not a plan.** The recommendation in [04 §5](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#5-product-strategy) stands: finish one thing.

---

## 9. Recommended next steps

| # | Action | Value | Complexity | Notes |
|:---:|---|:---:|:---:|---|
| K-1 | **Copy `architecture-rules.md` to Masaar and erp-frontend** | 🟢 Highest | **S** | One day; sets the portfolio standard |
| K-2 | **Extract P1 `platform/tenancy` and adopt in Masaar** | 🟢 Highest | **S** | Closes [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) with existing code — fix EB-H-1 first |
| K-3 | Write `docs/GLOSSARY.md`, shared across repos | 🟢 High | **S** | Reduces onboarding and key-person risk |
| K-4 | Extract P2–P6, P12 as a private monorepo package | 🟠 High | **M** | ~3 weeks; eliminates most duplication |
| K-5 | **Business rules catalogue, one module at a time** | 🟢 **Highest long-term** | **L** | 2–3 weeks with domain-expert pairing; start with Accounting and Tax |
| K-6 | Document the 15 state machines as JSON/YAML | 🟠 High | **S** | Language-agnostic; near-free from existing code |
| K-7 | Consolidate 3 circuit breakers and 3 rule engines | 🟡 Medium | **M** | After K-4 |
| K-8 | Decide P9/P10 productisation | 🟡 Medium | — | Blocked on [04 §5](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#5-product-strategy) |

**If only one thing is done from this document: K-2.** It converts a Critical security finding in Masaar into a code move, using a component that is already in production in a sibling repository.

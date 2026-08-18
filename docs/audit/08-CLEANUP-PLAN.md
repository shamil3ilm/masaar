# Portfolio Cleanup Plan — Masaar · erp-backend · erp-frontend

**Date:** 2026-08-18
**Related:** [00-EXECUTIVE-SUMMARY](00-EXECUTIVE-SUMMARY.md) · [02-SECURITY-AUDIT](02-SECURITY-AUDIT.md) · [06-PORTFOLIO-AUDIT-ERP](06-PORTFOLIO-AUDIT-ERP.md) · [07-KNOWLEDGE-EXTRACTION](07-KNOWLEDGE-EXTRACTION.md)

> **What this document is.** A single sequenced plan answering *"we need to clean up all of these."* Every item traces to a finding in documents 00–07. Nothing here has been implemented — this is the plan, awaiting approval.

---

## 1. The shape of the problem

The three repositories do not need the same treatment. Diagnosing them identically would waste the largest opportunity in the portfolio.

| Repo | Problem | Treatment |
|---|---|---|
| **Masaar** | Unfinished: unauthenticated endpoints, dead security code, accidental complexity | **Fix and simplify** |
| **erp-backend** | Under-adopted: excellent patterns applied to a fraction of the code | **Finish adopting its own rules** |
| **erp-frontend** | Undecided: a foundation with no application on it | **Make a strategic decision first** |

And one portfolio-level problem cutting across all three: **the same concern is solved three different ways in three places** — 3 circuit breakers, 3 idempotency mechanisms, 2 tenancy approaches, 2 rate-limiting models. In nearly every case `erp-backend`'s version is the best one and already exists in production.

**This reframes the cleanup.** The largest win is not writing better code; it is **moving code that already works from the repository that has it to the ones that don't.**

---

## 2. Track A — Propagate erp-backend's standards to Masaar

**The cheapest, highest-value track. Start here.** Roughly 3 weeks, and it closes a Critical security finding using code that is already running in production.

| # | Action | From → To | Closes | Cplx |
|:---:|---|---|---|:---:|
| A-1 | Copy `docs/architecture-rules.md` format, adapted | erp-backend → Masaar, erp-frontend | No rules doc exists in 2 of 3 repos | **S** |
| A-2 | Fix `BelongsToOrganization` to fail closed on read; add `TenantContext` for jobs | erp-backend (in place) | [EB-H-1](06-PORTFOLIO-AUDIT-ERP.md#-eb-h-1--the-tenant-global-scope-silently-disengages-outside-http-requests) | **M** |
| A-3 | Extract `platform/tenancy` package; adopt in Masaar; **delete `TenantIsolationGuard`** | erp-backend → shared → Masaar | [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) 🔴 | **M** |
| A-4 | Extract `platform/state-machine`; apply to Masaar's invoice lifecycle | erp-backend → Masaar | Ad-hoc `isEditable()` | **S** |
| A-5 | Extract `platform/idempotency`; re-base Masaar's `SubmissionIdempotency` | erp-backend → Masaar | 3 mechanisms → 1 | **S** |
| A-6 | Extract `platform/versioned-rules`; wire to Masaar's unused `rule_version` column | erp-backend → Masaar | ZATCA spec revisions ([R-9](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#6-risk-register)) | **S** |
| A-7 | Extract `platform/observability`; route Masaar's compliance logging through it | erp-backend → Masaar | [L-4](02-SECURITY-AUDIT.md#4-low-findings) | **S** |
| A-8 | Adopt `TenantRateLimitService` in Masaar | erp-backend → Masaar | [M-3](02-SECURITY-AUDIT.md#3-medium-findings) | **S** |

**Sequencing note.** A-2 must precede A-3, or the read-scope defect propagates into Masaar along with the fix. Package extraction should start as a **private monorepo package**, not a published one — publishing adds release overhead before the interfaces have settled.

---

## 3. Track B — Masaar: close the Critical findings

**Blocking. Nothing else in Masaar ships until this is green.** Detail and remediation code in [02 §6](02-SECURITY-AUDIT.md#6-remediation-sequence); roadmap phasing in [05 §7](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#7-roadmap).

| # | Action | Severity | Cplx |
|:---:|---|:---:|:---:|
| B-1 | Authenticate `/admin/*`; move route closures into `AdminController` | 🔴 [C-1](02-SECURITY-AUDIT.md#-c-1--the-admin-web-console-has-no-authentication) | **S** |
| B-2 | Authenticate `/portal/*`; derive tenant from session, never from `?org_id=` | 🔴 [C-2](02-SECURITY-AUDIT.md#-c-2--customer-portal-reads-tenant-identity-from-a-query-parameter-unauthenticated) | **S** |
| B-3 | Remove query-parameter credential extraction; rotate exposed keys | 🔴 [C-3](02-SECURITY-AUDIT.md#-c-3--api-key-and-secret-accepted-from-url-query-parameters) | **S** |
| B-4 | *(= A-3)* Structural tenant isolation | 🔴 [C-4](02-SECURITY-AUDIT.md#-c-4--tenantisolationguard-is-dead-code-tenant-isolation-has-no-defence-in-depth) | **M** |
| B-5 | Restrict `/metrics`; fix `.env.example` debug defaults | 🟠 H-6, M-6 | **S** |
| B-6 | Fix the CRL serial comparison; add a revoked-certificate fixture test | 🟠 [H-3](02-SECURITY-AUDIT.md#-h-3--crl-revocation-check-is-silently-non-functional) | **S** |
| B-7 | Allowlist OCSP/CRL endpoints; harden the fetch client | 🟠 [H-2](02-SECURITY-AUDIT.md#-h-2--ssrf-via-certificate-revocation-checking) | **S** |
| B-8 | Route-posture test asserting every route's expected auth | — | **S** |

---

## 4. Track C — Delete, don't refactor

Removal is cleanup. These items carry no production callers or are actively misleading, and deleting them is cheaper and safer than maintaining them. Git history preserves everything.

| # | Delete | Repo | Why | Cplx |
|:---:|---|---|---|:---:|
| C-1 | `TenantIsolationGuard` (300 lines, **0 call sites**) | Masaar | Implies protection that does not exist; replaced by A-3 | **S** |
| C-2 | 10 empty SDK directories; keep TypeScript + PHP | Masaar | Advertised in README, contain ≤3 files ([04 §1.1](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#11-documentation-that-is-wrong)) | **S** |
| C-3 | `ZatcaClientV1` + reduce `ZatcaInvoiceTransformer` to a DTO mapper | erp-backend | Duplicate regulated logic that will drift ([01 §1.1](01-DISCOVERY-AND-ARCHITECTURE.md#11-the-duplication-problem)) | **S** |
| C-4 | One of two circuit breakers; consolidate the third from erp-backend | Masaar, erp-backend | 3 implementations of one concern | **S** |
| C-5 | Speculative resilience services — `BackPressureManager`, `KillSwitchManager`, `HashChainAnomalyDetector`, `ArchivedTenantReconstructor`, `ComplianceSnapshot`, `QueueHealthMonitor`, `KeyCompromiseHandler`, `FallbackHandler` | Masaar | Built ahead of demand; ~25% of the compliance domain's class count ([03 §2.2](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#22-disposition-for-fatooraservices-44-classes)) | **S** |
| C-6 | One of three licensing systems; merge the rest into `Subscription` + `DeploymentLicence` | Masaar | ~25 files, three models, all named "license" ([01 §5.2](01-DISCOVERY-AND-ARCHITECTURE.md#52-anti-pattern-three-licensing-systems)) | **M** |

**Verify before deleting each item** — confirm zero callers, and for C-5 check `CertificateLineageService` and `VatPeriodTracker` separately, which were flagged as needing verification.

---

## 5. Track D — erp-backend: finish adopting its own rules

| # | Action | Finding | Cplx |
|:---:|---|---|:---:|
| D-1 | Audit 501 models carrying `organization_id` without the trait; apply it or annotate `// PLATFORM-LEVEL: <reason>` | [EB-H-2](06-PORTFOLIO-AUDIT-ERP.md#-eb-h-2--501-models-carry-organization_id-without-the-tenancy-trait) 🟠 | **M** |
| D-2 | Architectural test: model + `organization_id` column + no trait + no annotation ⇒ build fails | makes D-1 permanent | **S** |
| D-3 | Convert the top 8 cross-module flows to orchestrators (Rule 2) | [06 §3.2](06-PORTFOLIO-AUDIT-ERP.md#32-the-central-architectural-weakness--rule-2-is-125th-adopted) 🟠 | **L** |
| D-4 | Extract an abstract `Orchestrator` base class with `atomicCore()` / `domainEvent()` / `sideEffects()` | makes the phase discipline structural | **S** |
| D-5 | Architectural tests restricting `forceState()` and `withoutTenantCheck()` to permitted paths | EB-M-1 🟡 | **S** |
| D-6 | Generate OpenAPI from controllers + FormRequests | EB-5; unblocks FE-2 | **M** |
| D-7 | Consolidate 3 rule engines (`FraudRuleEngine`, `AutomationRuleService`, `TaxDeterminationService`) | [07 §4](07-KNOWLEDGE-EXTRACTION.md#4-engineering-pattern-catalogue) 🟡 | **M** |

**D-3 ordering.** Rank by financial blast radius. Suggested: goods receipt → payroll posting → work-order completion → payment application → inter-branch stock transfer → three-way match → credit-note reversal → period close.

---

## 6. Track E — Knowledge preservation

The work that survives every future rewrite. Detail in [07 §9](07-KNOWLEDGE-EXTRACTION.md#9-recommended-next-steps).

| # | Action | Cplx |
|:---:|---|:---:|
| E-1 | `docs/GLOSSARY.md` shared across all three repos | **S** |
| E-2 | Export the 15 state machines as JSON/YAML — language-agnostic, near-free from existing code | **S** |
| E-3 | **Business rules catalogue, one module at a time**, starting with Accounting and Tax | **L** |
| E-4 | Document tax rules as decision tables (jurisdiction × transaction type × date) | **M** |
| E-5 | Write the idempotency protocol as a one-page language-agnostic spec | **S** |

**E-3 is the highest long-term-value item in this entire plan.** It directly mitigates [R-14](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#6-risk-register) (key-person dependency), and 71 accounting services encode regulatory knowledge that exists nowhere else in written form. Requires domain-expert pairing — it cannot be done by reading code alone.

---

## 7. The one decision that must be made by a human

**`erp-frontend` cannot be cleaned up until someone decides what it is for.** This is a business decision and I am explicitly not making it.

| | **Option A — Build the ERP UI** | **Option B — Headless / API-first ERP** |
|---|---|---|
| **Scope** | 25+ modules × ~6 screens ≈ 150+ screens | Admin console + thin ops UI only |
| **Cost** | **XL** — 12+ months, the dominant portfolio cost | **L** |
| **Viability** | Only tractable via generation from D-6's OpenAPI spec. Hand-building is not viable at this team size. | Plays to the actual asset — 3,155 routes, 426 services |
| **Consistency** | Competes with Masaar for the same engineers | Consistent with Masaar's API-first positioning |
| **Risk** | Frontend becomes a permanent bottleneck | Narrower market; ERP buyers usually expect screens |

**Choosing neither is the current default, and it is the worst option.** Backend services continue accreting while 38 frontend files front 3,155 endpoints. The gap widens monthly.

**My recommendation, stated as a recommendation rather than a decision:** Option B, until Masaar reaches production readiness. The portfolio cannot fund a compliance platform, an ERP backend *and* 150 ERP screens simultaneously — that breadth is precisely how the current state arose.

---

## 8. Sequenced plan

Complexity: **S** ≤1 week · **M** 1–4 weeks · **L** 1–3 months · **XL** 3 months+

### Weeks 1–2 — Stop the bleeding *(blocking; no parallel feature work)*
`B-1` `B-2` `B-3` `B-5` `B-6` `B-7` `B-8`
→ **Exit:** zero Critical findings in Masaar; route-posture test green in CI.

### Weeks 3–6 — Propagate and delete *(highest value per hour)*
`A-1` `A-2` `A-3`(=`B-4`) `A-4` `A-5` `A-6` `A-7` `A-8` · `C-1` `C-2` `C-3` `C-4` `C-5` · `E-1` `E-2`
→ **Exit:** Masaar has structural tenancy, one idempotency mechanism, one circuit breaker, an architecture-rules doc, and ~25% less code. Portfolio duplication largely resolved.

### Weeks 7–12 — Consolidate and enforce
`C-6` · `D-1` `D-2` `D-4` `D-5` `D-6` · Masaar Phase 1 from [05 §7](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#7-roadmap) (generated OpenAPI, honest README, auth unification, conformance suite)
→ **Exit:** architectural invariants enforced by CI in both backends; documentation matches reality.

### Months 4–6 — Finish adoption
`D-3` (8 orchestrators) · `D-7` · `E-3` `E-4` · Masaar Phase 2 (KMS, sandbox, SDKs, one `/v1` surface)
→ **Exit:** Masaar is self-service-evaluable; erp-backend's cross-module flows are transactionally owned.

### Months 6+ — Strategy-dependent
`erp-frontend` per §7 · Masaar Phase 3 DX (explanation engine, debugger, CLI) · package productisation per [07 §8](07-KNOWLEDGE-EXTRACTION.md#8-product-opportunities)

---

## 9. What I recommend doing first

If the plan above is too large to start all at once, these five items in this order deliver the most in the least time:

1. **B-1, B-2, B-3** — three unauthenticated entry points, roughly a day of work each. Currently the largest liability in the portfolio.
2. **A-1** — copy the architecture-rules format to Masaar. One day. Sets the standard everything else is measured against.
3. **A-2 + A-3** — fix the tenancy trait, extract it, adopt it in Masaar, delete the dead guard. Closes a Critical finding with code that already exists.
4. **C-2** — delete ten empty SDK directories and correct the README. An hour, and it removes a standing credibility risk with the primary customer persona.
5. **E-1** — the shared glossary. One day, and it is what new engineers and AI assistants need most.

---

## 10. Status and next step

**Nothing in this plan has been implemented.** Documents 00–08 are analysis only, per the audit brief.

Cleanup execution is the natural next step, but it changes production code across three repositories and should be authorised deliberately — ideally one track at a time, with the security track first. Track B (weeks 1–2) is the recommended starting point and is self-contained.

Two items need a human decision before their tracks can proceed:
- **§7** — `erp-frontend` Option A vs B. Blocks all frontend cleanup.
- **[04 §5](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#5-product-strategy)** — which product is being built first. Blocks C-6 scoping and all productisation in [07 §8](07-KNOWLEDGE-EXTRACTION.md#8-product-opportunities).

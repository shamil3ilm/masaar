# 00 — Compliance Surface Map

**Audit date:** 2026-08-23
**Auditor scope:** read-only across `Masaar`, `erp-backend`, `erp-frontend`
**Branch audited:** `chore/security-remediation-and-cleanup` @ `e83d8fe`

---

## Correction to the brief, up front

The brief said:

> Where does ZATCA e-invoicing logic actually live? I believe it is in `./Zatca`
> — its migrations reference `payment_means_code`, `is_third_party`, `is_nominal`,
> `is_export`, `is_summary`, `is_self_billed`.

**`c:\laragon\www\Zatca` does not exist.** There is no directory of that name on
this machine (`c:\laragon\www` contains: `Masaar`, `assist`, `axiom`, `docs`,
`erp-backend`, `erp-frontend`, `foster-sear`, `laravel.apimaster.zilmoney.com`,
`live.ocwapi.com`, `live.onlinecheckwriter.com`, `myapp`, `portfolio`,
`wed_cert`). This is recorded in [99-denied.md](99-denied.md).

The six columns you named are real, and they are **in Masaar itself**:

- [`database/migrations/0080_invoices.php:50-54`](../database/migrations/0080_invoices.php#L50-L54)
  — `is_third_party`, `is_nominal`, `is_export`, `is_summary`, `is_self_billed`,
  each carrying a `->comment()` naming its ZATCA BT-3 bit.
- [`database/migrations/0080_invoices.php:31`](../database/migrations/0080_invoices.php#L31)
  — `payment_means_code`.

**So this audit is against three repos, not four, and the ZATCA implementation
is audited where it actually lives: `Masaar`.** Nothing below is forced onto the
wrong repo.

### Where the memory came from

Not an assumption — I found it. **Masaar's git remote is
`https://github.com/shamil3ilm/zatca.git`.** There is no `Zatca` directory, but
there *is* a `zatca` repository, and it is this one. You were recalling the repo
name, which does not match its directory name.

The naming is not merely inconsistent — **it is crossed**:

| Directory | What it actually is | Local remote URL | **Real GitHub repo** |
|---|---|---|---|
| `Masaar` | the compliance platform | `zatca` | `zatca` |
| `erp-backend` | the ERP | `qarar` *(stale, 301)* | **`masaar`** |
| `erp-frontend` | the ERP's UI | `masaar-frontend` | `masaar-frontend` |

The name `masaar` on GitHub belongs to the **ERP**, not to the product called
Masaar. And `qarar` no longer exists as a name — it 301-redirects, which git
follows silently, so the local URL has been wrong without ever failing.

Neither the folder name nor the remote URL is a reliable guide to what a repo
holds. That is the whole cause of the brief's wrong premise, and it is worth
fixing before anything else in this audit.

---

## 1. What Masaar is

A **multi-jurisdiction e-invoicing compliance API** — a Laravel 12 / PHP 8.4
application that other people's ERPs call over HTTP to get invoices signed,
cleared and reported to a tax authority.

Self-described in [`README.md:1-11`](../README.md#L1-L11):

| Country | Authority | Status per README |
|---|---|---|
| 🇸🇦 Saudi Arabia | ZATCA (Fatoora Phase 2) | "Feature complete — conformance suite pending" |
| 🇦🇪 UAE | FTA (Peppol PINT AE) | "In development" |
| 🇶🇦 Qatar | GTA | "Planned" |

The README's own caveat is unusually honest and matches what I found:

> It has **not** yet been validated against ZATCA's official conformance
> fixtures, and signing keys are not yet held in a managed KMS.
> — [`README.md:13-19`](../README.md#L13-L19)

That claim is accurate. See [01-summary.md](01-summary.md).

### Domain layout

`app/Domains/` — ten domains, ZATCA confined to one:

```
app/Domains/
├── Audit/          audit trail
├── Auth/           JWT + session
├── Compliance/     ← the entire compliance surface
│   ├── ComplianceRouter.php        jurisdiction dispatch
│   ├── Contracts/                  ComplianceEngine, SubmissionResult, ValidationResult
│   ├── Fatoora/                    🇸🇦 ZATCA — 46 files, ~14,000 LOC
│   └── FTA/                        🇦🇪 UAE — 10 files, ~1,000 LOC
├── Invoice/        Invoice + InvoiceLine aggregate, enums
├── Licensing/      per-customer licence keys, quota, phone-home
├── Logging/
├── Organization/   tenant, branch (= EGS unit), compliance profile
├── Pipeline/       one-call "draft + submit" convenience path
├── Platform/       Masaar-staff console
└── Webhook/        outbound notification
```

`Compliance/Fatoora/` is the answer to "where does ZATCA logic live":

| Sub-package | Notable contents |
|---|---|
| `Services/` (23 files) | `XmlBuilder` (978 L), `XadesSigner` (1021 L), `CertificateService` (905 L), `KillSwitch` (702 L), `SubmissionTracker` (549 L), `InvoiceValidator` (532 L), `Submitter` (518 L), `OfflineQueue` (485 L), `EcdsaSigner`, `TlvEncoder`, `InvoiceHasher`, `QrCodeGenerator`, `CsidOnboarding`, `CredentialStore`, `CircuitBreaker`, `DuplicateDetector`, `VatPeriodTracker`, `TimestampValidator` |
| `Client/` | `FatooraClient` (366 L) — the only place that speaks HTTP to ZATCA |
| `Models/` | `InvoiceSubmission`, `ChainState`, `ChainEntry`, `OfflineItem`, `SubmissionIdempotency`, `SubmissionStateLog` |
| `Jobs/` | `ProcessFatooraSubmission` (419 L) |
| `Events/` | `InvoiceSubmitted/Cleared/Reported/Rejected/Warning/Failed` |
| `DTOs/` | `InvoiceXmlData` (444 L), `CsrData`, `CsidResponse`, `FatooraResponse`, `QrCodeData`, `AddressData` |
| `Http/Controllers/` | `OnboardingController`, `BranchOnboardingController`, `ComplianceController` |

---

## 2. How the repos relate

**They are one system split across repos, plus one that is a genuinely separate
product.** Specifically:

```
┌──────────────────┐  HTTP/JSON, licence-key auth
│   erp-backend    │ ─────────────────────────────────┐
│  (Laravel ERP)   │                                  │
│  owns: sales,    │                                  ▼
│  manufacturing,  │                     ┌────────────────────────────┐
│  accounting      │                     │          Masaar            │
└────────┬─────────┘                     │  Compliance API platform   │
         │ REST                          │  OWNS all ZATCA logic:     │
         ▼                               │  UBL · ECDSA · XAdES · QR  │
┌──────────────────┐                     │  ICV/PIH · CSID · submit   │
│   erp-frontend   │                     └────────────┬───────────────┘
│ (Next/Turborepo) │                                  │ HTTPS
│ admin·staff·portal│                                 ▼
└──────────────────┘                          ZATCA Fatoora API
```

### Evidence for the erp-backend → Masaar direction

- [`erp-backend/config/zatca-integration.php:5`](file:///c:/laragon/www/erp-backend/config/zatca-integration.php)
  — `'url' => env('ZATCA_INTEGRATION_URL', 'http://localhost:8001/api/v1')`.
  erp-backend calls a **separate HTTP service** for compliance.
- [`erp-backend/app/Services/Compliance/CompliPayClient.php:17-21`](file:///c:/laragon/www/erp-backend/app/Services/Compliance/CompliPayClient.php)
  — docblock: *"Communicates with the ZATCA middleware project for e-invoicing
  compliance in Saudi Arabia."* That middleware project is Masaar.
- [`erp-backend/app/Orchestrators/Sales/PostInvoiceOrchestrator.php:194-245`](file:///c:/laragon/www/erp-backend/app/Orchestrators/Sales/PostInvoiceOrchestrator.php)
  — on posting a sales invoice, calls `$this->compliPayClient->submitInvoice($invoice)`
  and stores `compliance_status`, `compliance_uuid`, `compliance_hash`,
  `compliance_qr_code` back onto the ERP invoice.
- Masaar exposes exactly that surface: [`routes/api/partner.php`](../routes/api/partner.php)
  is documented as *"an ERP acting for its customer, over /v1"*
  ([`routes/api.php:17`](../routes/api.php#L17)).

**erp-backend does NOT reimplement the cryptography.** Its `Services/Compliance/`
holds only transport adapters — `ZatcaClientV1` is 46 lines and delegates to
`CompliPayClient`; `ZatcaInvoiceTransformer` (159 L) just maps an ERP `Invoice`
model to Masaar's JSON payload shape. There is no signer, no UBL builder, no
hash chain in erp-backend. This is the correct division and it is real, not
aspirational.

Two caveats worth naming:
- erp-backend also contains `QatarGtaEInvoiceService.php` — a second, *local*
  compliance path that bypasses Masaar. Overlap. See
  [07-consolidation.md](07-consolidation.md).
- erp-backend has its own `Services/Compliance/CircuitBreaker.php`, duplicating
  Masaar's `Fatoora/Services/CircuitBreaker.php`. Two implementations of the
  same idea in two repos.

### erp-frontend

A pnpm/Turborepo monorepo — `apps/admin`, `apps/staff`, `apps/portal`, plus
`packages/api-client`, `packages/types`, `packages/ui`. It is the **UI for
erp-backend**, not for Masaar. Masaar ships its own server-rendered admin
console and customer portal (`routes/web.php`, guarded by `platform.admin` and
`portal.tenant`). erp-frontend carries no ZATCA logic.

### Are these four separate products?

Three repos, **two products**:

1. **Masaar** — a compliance API. Sellable on its own, to any ERP.
2. **erp-backend + erp-frontend** — one ERP product, split backend/frontend.
   erp-frontend is not independently meaningful.

Masaar's README describes an intended monorepo where `erp/` becomes a git
submodule ([`README.md:23-35`](../README.md#L23-L35)). **That has not happened** —
there is no submodule, no `erp/` directory inside Masaar, and the repos are
independent working copies.

---

## 3. Which repo owns invoice issuance

**Split, and correctly so:**

| Concern | Owner |
|---|---|
| Commercial invoice (pricing, customer, GL posting) | erp-backend |
| Legal/tax invoice (UBL, ICV, PIH, signature, QR, submission) | **Masaar** |

Masaar **does** issue invoices itself — it is not merely a signing library.
It holds its own `invoices` + `invoice_lines` tables
([`database/migrations/0080_invoices.php`](../database/migrations/0080_invoices.php)),
allocates its own ICV, and has a direct-authoring API
([`app/Domains/Invoice/Http/Controllers/InvoiceController.php:51`](../app/Domains/Invoice/Http/Controllers/InvoiceController.php#L51))
alongside the partner/ERP path. `erp_reference_id` on the invoices table is the
correlation key back to the ERP.

This means a Masaar customer can either (a) use it as a compliance backend
behind their own ERP, or (b) author invoices in it directly. Both paths exist
in code.

---

## 4. Single- or multi-tenant

**Multi-tenant, structurally — and this is verified by a passing test.**

- Tenant root: `organizations`
  ([`0050_organizations.php`](../database/migrations/0050_organizations.php)),
  with `organization_groups` above it
  ([`0040_organization_groups.php`](../database/migrations/0040_organization_groups.php)).
- Every compliance table carries `org_id` with a cascading FK — `invoices`
  ([`0080_invoices.php:15`](../database/migrations/0080_invoices.php#L15)),
  `hash_chain_state` and `hash_chain_history`
  ([`0160_hash_chain.php:14,27`](../database/migrations/0160_hash_chain.php#L14)).
- Enforcement is a global Eloquent scope, not per-query discipline:
  `Organization/Concerns/BelongsToTenant.php` + `TenantScope.php`, applied on
  `Invoice` ([`app/Domains/Invoice/Models/Invoice.php:29`](../app/Domains/Invoice/Models/Invoice.php#L29))
  and `ChainState` ([`.../Models/ChainState.php:26`](../app/Domains/Compliance/Fatoora/Models/ChainState.php#L26)).
- **VERIFIED:** `tests/Feature/Security/TenantIsolationTest.php` — 7 passing
  assertions including *"missing tenant context yields no rows"* and *"created
  records inherit the active tenant"*. Also `JwtCrossTenantTest`,
  `ConsoleTenantScopeTest`, and an architecture test
  (`RawTenantQueryTest`) that fails the build on raw unscoped SQL.

Below the tenant sits `branches`
([`0070_branches.php`](../database/migrations/0070_branches.php)) — Masaar's
model of a **ZATCA EGS unit**. `Invoice::branch()` is documented as deciding
which certificate signs
([`app/Domains/Invoice/Models/Invoice.php:238-247`](../app/Domains/Invoice/Models/Invoice.php#L238-L247)),
and there is a dedicated `BranchOnboardingController` (343 L). The hash chain,
however, is keyed on `org_id` alone, not on branch — see
[06-risks.md](06-risks.md), which flags this as a spec question.

Alongside `organizations` sits `compliance_profiles`
([`0060_compliance_profiles.php`](../database/migrations/0060_compliance_profiles.php)) —
one per (tenant, jurisdiction), which is how one organization can hold both a
ZATCA and an FTA registration. `MultiJurisdictionTest` and
`MultiProfileRoutingTest` cover it.

---

## 5. Where the compliance surface begins and ends

Everything that could fail a ZATCA audit is inside these boundaries:

| Boundary | Path |
|---|---|
| Entry (ERP) | `routes/api/partner.php` → `PipelineController` |
| Entry (direct) | `routes/api/tenant.php` → `InvoiceController::store` |
| Entry (onboarding) | `OnboardingController`, `BranchOnboardingController` |
| Core | `app/Domains/Compliance/Fatoora/**` |
| Chain state | `hash_chain_state`, `hash_chain_history`, `invoices.icv` |
| Egress | `Fatoora/Client/FatooraClient.php` — **the only** outbound HTTP to ZATCA |
| Async | `Jobs/ProcessFatooraSubmission`, `offline_queue`, `submissions` |
| Operator | `app/Console/Commands/Fatoora*.php`, `VerifyHashChain`, `RotateCredentialKey`, `CheckCertificateExpiry` |

A single egress class is a good property: there is exactly one place where a
wrong URL, a missing TLS verification or a dropped retry could occur.

---

## 6. One thing to know before reading further

**The test suite cannot run with the default PHP on this machine.**
`php -v` is 8.2.28; `composer.json` requires `^8.4`, so `php artisan test`
aborts in `vendor/composer/platform_check.php` with exit code 0 — a **silent
false pass**. Run under `C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64\php.exe`
and the suite is green:

```
Tests:  3 skipped, 704 passed (1546 assertions)
Duration: 30.99s
```

Every "VERIFIED" in this audit rests on that run.

A prior in-repo audit exists at [`docs/audit/`](../docs/audit/) (11 documents,
last updated 2026-08-18). I read it *after* forming my own findings and have
re-verified rather than repeated its claims; where I disagree with it I say so.
Its `09-WORK-MAP.md` reports 358 tests; the suite is now 704, so real work has
landed since.

# 02 — Repository Inventory

Three repositories. Only `Masaar` implements compliance — see [00-map.md](00-map.md).

---

## Masaar — the compliance platform · **ACTIVE**

| | |
|---|---|
| Framework | Laravel **v12.66.0** (`composer.lock`) |
| PHP constraint | `^8.4` ([`composer.json:12`](../composer.json#L12)) |
| Test framework | Pest **v4.7.8** + PHPUnit |
| Key deps | `phpseclib/phpseclib ^3.0` (EC keys/CSR), `tymon/jwt-auth ^2.2` |
| Frontend | Vite + Tailwind (server-rendered Blade console/portal); no SPA |
| `app/` LOC | **35,104** across ~250 PHP files |
| `tests/` LOC | **15,901** across **117** test files |
| `Fatoora/` LOC | **15,106** — 43% of all application code |

### Directory layout (2 levels)

```
Masaar/
├── app/
│   ├── Console/Commands/     17 commands + Concerns/
│   ├── Domains/              Audit Auth Compliance Invoice Licensing
│   │                         Logging Organization Pipeline Platform Webhook
│   ├── Http/                 Controllers/ Responses/
│   ├── Providers/
│   └── Support/
├── config/                   18 files incl. fatoora.php, fta.php, security.php
├── database/migrations/      16 files, ordinal-numbered (0010…0190)
├── docs/                     audit/ sa/ ae/ qa/ architecture/ superpowers/
│                             + openapi.yaml, schema.sql
├── routes/                   api.php + api/{public,tenant,partner,platform,deprecated}.php
│                             licensing.php, console.php, web.php
├── sdks/                     11 languages
├── tests/                    Feature/{Api,Architecture,Compliance,Invoice,Licensing,
│                             Organization,Pipeline,Platform,Security,Webhook} + Unit/ + E2e/
├── cloudflare-worker/
└── docker/ + Dockerfile + docker-compose{,.prod}.yml
```

### Tests, CI, Docker, env

- **Tests:** 123 files. Suite on PHP 8.4.12: **715 passed, 15 skipped, 0 failed, 1640 assertions, ~30s.** Twelve skips are `ZatcaConformanceTest` without `ZATCA_SDK_PATH`; three are `SecretFileTest` asserting POSIX modes Windows does not enforce.
- **Notable:** a whole `tests/Feature/Architecture/` suite (18 files) enforcing
  structure — `NoShellOutTest`, `RawTenantQueryTest`, `OpenapiDriftTest`,
  `SdkTypesDriftTest`, `ConfigKeyTest`, `NamingConventionTest`. Rare and good.
- **CI:** `.github/workflows/ci.yml` + `build-and-push.yml`. Pins `PHP_VERSION: '8.4'`
  with a comment explaining why. Runs `composer validate --strict`, the suite,
  and (per prior audit) `composer audit --locked`.
- **Docker:** `Dockerfile`, `docker-compose.yml`, `docker-compose.prod.yml`, `docker/`.
- **`.env.example`:** present, 8,842 bytes, and there is an `EnvExampleTest`
  architecture test that keeps it in step with `config/`.

### Git

| | |
|---|---|
| Branch | `main` |
| Remote | `github.com/shamil3ilm/masaar` |
| Last commit | **2026-08-24** `360852e` |
| Commits in 90 days | **117** |
| Unpushed | none |
| Stashes | none |

### Files over 1000 lines

Only two, and both are justifiable:
- [`app/Domains/Compliance/Fatoora/Services/XadesSigner.php`](../app/Domains/Compliance/Fatoora/Services/XadesSigner.php) — 1,021
- [`app/Console/Commands/FatooraOnboarding.php`](../app/Console/Commands/FatooraOnboarding.php) — 1,016

Next tier (800–1000): `XmlBuilder` 978, `CertificateService` 905. All four sit
above your 800-line house rule; none is egregious.

### TODO/FIXME density

**Zero.** `grep -rn "TODO\|FIXME\|HACK\|XXX" app/ --include="*.php"` → 0 matches
across 35k lines. Unusual. The known-incomplete parts are documented in prose
docblocks instead (e.g. `CredentialStore.php:29-31` on the missing KMS), which
is why they don't show up as markers.

### README vs reality

**Accurate, including its caveats** — which is rare. Verified claims:
- ✅ "Requires PHP 8.4+" — true, and the reason the suite silently no-ops on 8.2.
- ✅ "has **not** yet been validated against ZATCA's official conformance
  fixtures" — confirmed; no XSD, no fixtures, no stored ZATCA response.
- ✅ "signing keys are not yet held in a managed KMS" — confirmed at
  [`CredentialStore.php:60-76`](../app/Domains/Compliance/Fatoora/Services/CredentialStore.php#L60-L76).
- ✅ SDK table ("None are published to a package registry yet", "🟠 Skeleton") —
  matches `sdks/`.
- ⚠️ **One overstatement:** the status table says ZATCA is "🟢 Feature
  complete — conformance suite pending". The second half is exactly right; the
  first is generous while `CustomizationID` remains unchecked against the spec
  and its sibling constant turned out to be wrong.
- ⚠️ **One stale structural claim:** `README.md:23-35` describes `erp/` as a
  "future git submodule" inside a `Masaar/` monorepo parent. No submodule
  exists; the three repositories are independent working copies.

**Verdict: healthy, actively developed, honestly documented.**

---

## masaar-erp-backend — the ERP · **DORMANT**

| | |
|---|---|
| Framework | Laravel `^12.0`, PHP `^8.2` |
| `app/` LOC | **274,398** across **2,081** PHP files |
| Tests | 189 test files; `TESTS.md` present |
| CI | **none** — no `.github/` directory |
| Branch | `main` |
| Remote | `github.com/shamil3ilm/masaar-erp-backend` |
| Last commit | **2026-08-24** `86e1923` |
| Substantive feature work | **May 2026** — the two later commits are a refactor landing and a rename |
| Uncommitted | none |
| Tests | **2157 passed, 5500 assertions** |

### What the last commit contains

A single large refactor: the `Actions/Commands/DTOs/Queries/UseCases` CQRS layer
collapsed into the services and listeners already doing the work, SAP prefixes
stripped from domain names (`PmOrder`→`CounterBasedOrder`,
`QmDynamicModificationRule`→`DynamicModificationRule`), plus widget providers,
MRP capacity planning and demand forecasting. 41 deletions, 34 additions, 61
modifications and 13 git-detected renames at 61–98% similarity.

It passes. The repository is dormant, not broken.

### Its ZATCA code — a thin client, correctly

masaar-erp-backend does **not** duplicate the cryptography. What it has:

| File | LOC | What it is |
|---|---|---|
| `Services/Compliance/CompliPayClient.php` | 552 | HTTP client for "the ZATCA middleware project" (= Masaar) |
| `Services/Compliance/ZatcaInvoiceTransformer.php` | 159 | ERP `Invoice` → Masaar JSON payload |
| `Services/Compliance/ZatcaClientV1.php` | 46 | Adapter onto an `ExternalApiClient` contract |
| `Orchestrators/Sales/PostInvoiceOrchestrator.php` | — | Calls it on invoice post; stores `compliance_*` back |
| `Http/Controllers/Api/V1/Compliance/ZatcaWebhookController.php` | — | Receives Masaar's callbacks |
| `Http/Middleware/VerifyZatcaWebhook.php` | — | Verifies the callback signature |
| `Jobs/RetryComplianceSubmission.php` | — | Retries a failed handoff |
| `config/zatca-integration.php` | 13 | `localhost:8001/api/v1` by default |

No signer, no UBL builder, no hash chain. The boundary is drawn in the right place.

**Two genuine overlaps with Masaar:**
- `Services/Compliance/CircuitBreaker.php` duplicates
  `Masaar/app/Domains/Compliance/Fatoora/Services/CircuitBreaker.php` (334 L).
- `Services/Compliance/QatarGtaEInvoiceService.php` is a **second, local**
  compliance path that bypasses Masaar entirely — while Masaar's own `docs/qa/`
  claims Qatar. Two answers to one question.

**Verdict: dormant, not dead. Uncommitted work at risk. No CI.**

---

## masaar-erp-frontend — the ERP UI · **DORMANT**

| | |
|---|---|
| Stack | Turborepo `^2.0` + pnpm workspaces, React `^19.0`, TypeScript `^5.4` |
| Apps | `apps/admin`, `apps/staff`, `apps/portal` |
| Packages | `packages/api-client`, `packages/types`, `packages/ui` |
| Remote | `github.com/shamil3ilm/masaar-erp-frontend` |
| Last commit | **2026-08-24** `e2ec76f` |
| Substantive feature work | **May 2026** — design system; later commits are a formatter consolidation and the `@masaar` scope rename |
| Uncommitted | none |
| CI | none found |
| ⚠️ | Workspace scope renamed `@erp/*`→`@masaar/*`; needs one `pnpm install` to rebuild `node_modules` symlinks |

Contains **no ZATCA logic**. It is the UI for masaar-erp-backend and has no independent
meaning; the two are one product. Note the commit message says "shared Masaar
design system" — the naming bleeds across repos even though the code does not.

**Verdict: dormant. Cannot ship without masaar-erp-backend; shares its fate.**

---

## Abandonment flags

| Repo | Flag |
|---|---|
| Masaar | 🟢 **Active** — 117 commits/90d, clean, pushed, CI green |
| masaar-erp-backend | 🟠 **Dormant** — no substantive feature work since May 2026 · **no CI** · 274k LOC |
| masaar-erp-frontend | 🟠 **Dormant** — no substantive feature work since May 2026 · no CI · cannot ship without the backend |

Both ERP repos are clean and fully pushed, so nothing is at risk of loss. The
flag is about attention, not fragility: **one live project, two parked ones.**
masaar-erp-backend having no CI at 274k LOC is the more serious of the two —
its 2157 tests only run when someone remembers to run them.

Folder names, repository names and product identity agree across all three:

| Directory | GitHub repo | What it is |
|---|---|---|
| `Masaar` | `masaar` | compliance platform |
| `masaar-erp-backend` | `masaar-erp-backend` | ERP |
| `masaar-erp-frontend` | `masaar-erp-frontend` | ERP UI |

Verified by `git ls-remote` against each clone's `main`. Old names redirect.
See [00-map.md](00-map.md).

# 02 — Repository Inventory

Three repos, not four. `c:\laragon\www\Zatca` does not exist — see [00-map.md](00-map.md).

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

- **Tests:** 117 files. Suite result on PHP 8.4.12: **704 passed, 3 skipped, 0 failed, 1546 assertions, 30.99s.**
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
| Branch | `chore/security-remediation-and-cleanup` |
| Last commit | **2026-08-23** `e83d8fe` *"docs(audit): record two conformance questions rather than guess at them"* |
| Commits in 90 days | **107** |
| Remote | `origin` → **`github.com/shamil3ilm/zatca.git`** |
| Upstream tracking | **not set** for this branch (`git log @{u}..HEAD` errors) |
| Unpushed | **135 commits ahead of `origin/main`**, 0 behind |
| `origin/main` HEAD | `262514d`, **2026-02-03** — nearly 7 months stale |
| Stashes | none |

> 🔎 **This is where your "`./Zatca`" memory came from.** Masaar's GitHub remote
> is literally named **`zatca`**. There is no `Zatca` *directory*, but there is a
> `zatca` *repository* — and it is this one. The brief's instinct was right about
> the name and wrong only about the shape.

Uncommitted (7 entries):
```
 M .claude/settings.json          M app/Console/Commands/FatooraGenerateCsr.php
 M README.md                      M app/Console/Commands/FatooraOnboarding.php
 M docs/audit/00-EXECUTIVE-SUMMARY.md
 M app/Console/Commands/FatooraSandboxTest.php
?? .claude/settings.json.bak      ?? app/Console/Commands/Concerns/
?? tests/Feature/Compliance/SecretFileTest.php
```
This is coherent work-in-progress: a `WritesSecrets` trait being extracted into
`Concerns/` so the onboarding commands write keys `0600` in a `0700` directory,
with `SecretFileTest.php` as its test. Not abandoned debris.
⚠️ `.claude/settings.json.bak` is untracked and unignored — `.gitignore` covers
`.env.*` but not `*.bak` generally.

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
  complete". Item 34 (cleared XML) is a functional gap, not just an unverified
  one. "Feature complete" is not quite true.
- ⚠️ **One stale structural claim:** `README.md:23-35` describes `erp/` as a
  "future git submodule" inside a `Masaar/` monorepo parent. No submodule
  exists; the repos are independent working copies.

**Verdict: healthy, actively developed, honestly documented.**

---

## erp-backend — the ERP · **DORMANT, with a large uncommitted change**

| | |
|---|---|
| Framework | Laravel `^12.0`, PHP `^8.2` |
| `app/` LOC | **274,398** across **2,081** PHP files |
| Tests | 189 test files; `TESTS.md` present |
| CI | **none** — no `.github/` directory |
| Branch | `main` |
| Last commit | **2026-05-24** `89b055f` *"test: complete Manufacturing module coverage + fix pre-existing failures"* |
| Commits in 90 days | **0** |

### 🚩 149 uncommitted changes, 41 of them staged deletions

```
M  .env.example    M  .gitignore    M  CLAUDE.md
D  analyze.php
D  app/Actions/Accounting/CreateJournalEntryAction.php
… 40 more staged deletions
```

Three months untouched with a large, **staged but uncommitted** refactor sitting
in the index. Whatever the intent was, the context is gone. This is the single
most fragile state in the portfolio: the next person to run `git checkout` or
`git stash` here can lose work that has no commit and no upstream.

### Its ZATCA code — a thin client, correctly

erp-backend does **not** duplicate the cryptography. What it has:

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

## erp-frontend — the ERP UI · **DORMANT**

| | |
|---|---|
| Stack | Turborepo `^2.0` + pnpm workspaces, React `^19.0`, TypeScript `^5.4` |
| Apps | `apps/admin`, `apps/staff`, `apps/portal` |
| Packages | `packages/api-client`, `packages/types`, `packages/ui` |
| Last commit | **2026-05-24** `4b40835` *"feat: unify auth layout + align all apps to shared Masaar design system"* |
| Commits in 90 days | **0** |
| CI | none found |

Contains **no ZATCA logic**. It is the UI for erp-backend and has no independent
meaning; the two are one product. Note the commit message says "shared Masaar
design system" — the naming bleeds across repos even though the code does not.

**Verdict: dormant. Cannot ship without erp-backend; shares its fate.**

---

## Abandonment flags

| Repo | Local remote URL | **Actual GitHub repo** | Flag |
|---|---|---|---|
| Masaar | `shamil3ilm/zatca` | `shamil3ilm/zatca` | 🟢 Active — 107 commits/90d. **`main` 28 ahead; feature branch 135 ahead of `origin/main`.** |
| erp-backend | `shamil3ilm/qarar` **(stale)** | **`shamil3ilm/masaar`** | 🟠 0 commits/90d · 149 uncommitted incl. 41 staged deletions · no CI |
| erp-frontend | `shamil3ilm/masaar-frontend` | `shamil3ilm/masaar-frontend` | 🟠 0 commits/90d · `main` 1 **behind** origin · dependent on a dormant backend |

> **Correction.** An earlier revision of this audit reported erp-backend's repo
> as `qarar`. That is the **stale local remote URL** — `qarar` returns HTTP 301
> and resolves to `shamil3ilm/masaar` (verified via the GitHub API; `pushed_at`
> 2026-05-24, matching erp-backend's last commit). Git has been following the
> redirect silently, so nothing broke and nothing surfaced the drift.
>
> **The names are crossed:** the directory `Masaar` is the *compliance platform*
> under repo `zatca`; the directory `erp-backend` is the *ERP* under repo
> `masaar`. Reading either the folder name or the remote URL alone gives the
> wrong answer, which is precisely the confusion that produced the wrong premise
> in this audit's brief.

The most urgent operational fact in this audit is not a compliance gap:
**135 commits — effectively the entire ZATCA implementation and all of the
security remediation — exist only on this Windows machine.** `origin/main` was
last updated **2026-02-03**, almost seven months ago. A disk failure today
costs you the project, not a sprint.

**Repo naming is inconsistent across the board** and is actively causing
confusion (it caused the premise error in this audit's brief):

| Directory | GitHub repo | Product name |
|---|---|---|
| `Masaar` | `zatca` | Masaar |
| `erp-backend` | `qarar` | — |
| `erp-frontend` | `masaar-frontend` | — (it is the *ERP* frontend, not Masaar's) |

Three names for two products, and `masaar-frontend` names the wrong parent.

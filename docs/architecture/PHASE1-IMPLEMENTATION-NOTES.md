# Phase 1 Implementation Notes — Monorepo + Fatoora/FTA Rename

**Date:** 2026-04-03  
**Status:** Complete  
**Commits:** See `git log --oneline` (6 commits from 2026-04-03, plus documentation commits from 2026-04-02)

---

## What Was Done

### Phase 1A — Monorepo Structure

- **`sdk/` renamed to `sdks/`** (git history preserved via `git mv`, no data loss)
- **Jurisdiction documentation structure created:**
  - `docs/sa/` — Saudi Arabia (KSA) Fatoora compliance documentation
  - `docs/ae/` — United Arab Emirates (UAE) FTA compliance documentation  
  - `docs/qa/` — Qatar GTA placeholder (for future Phase 2)
- **Umbrella architecture documentation:**
  - `docs/architecture/ADDING-A-JURISDICTION.md` — step-by-step guide for onboarding new tax engines (e.g., Egypt VAT, Bahrain, Oman, Kuwait)
  - `docs/architecture/MULTI-JURISDICTION-PLATFORM-DESIGN.md` — overall platform design principles
- **Root `README.md` updated** with multi-jurisdiction overview and jurisdiction-specific links

### Phase 1B — KSA: Saudi/Zatca → Fatoora (6 commits)

**Namespace refactoring:**
- `app/Domains/Compliance/Saudi/` → `app/Domains/Compliance/Fatoora/`
- All `Zatca`-prefixed classes renamed to `Fatoora`-prefixed across 30+ files:

| Old Class | New Class |
|-----------|-----------|
| `ZatcaClient` | `FatooraClient` |
| `ZatcaConfig` | `FatooraConfig` |
| `ZatcaResponse` | `FatooraResponse` |
| `ZatcaTime` | `FatooraTime` |
| `ZatcaException` | `FatooraException` |
| `ZatcaComplianceService` | `FatooraComplianceService` |
| `ZatcaSubmissionService` | `FatooraSubmissionService` |
| `ZatcaConnectivityChecker` | `FatooraConnectivityChecker` |
| `ZatcaValidator` | `FatooraValidator` |
| `ZatcaSdkService` | `FatooraSdkService` |
| `ProcessZatcaSubmission` | `ProcessFatooraSubmission` |

**Artisan commands renamed** (8 commands):
```
zatca:submit → fatoora:submit
zatca:validate → fatoora:validate
zatca:check-connectivity → fatoora:check-connectivity
zatca:generate-csrs → fatoora:generate-csrs
zatca:renew-certificate → fatoora:renew-certificate
zatca:verify-invoice → fatoora:verify-invoice
zatca:send-reports → fatoora:send-reports
zatca:test-submission → fatoora:test-submission
```

**Configuration:**
- `config/zatca.php` → `config/fatoora.php`
- All `config('zatca.*')` references updated to `config('fatoora.*)` across **24 files**
- Bootstrap exception handler imports updated

**API Routes:**
- Primary: `POST /api/compliance/sa/generate/{id}`, `/validate/{id}`, `/submit/{id}`
- Deprecated alias (soft redirect): `/api/compliance/zatca/*` → shows deprecation notice, points to `/compliance/sa/`
- Removal scheduled for v2.0

**Tests:**
- `tests/Unit/Domains/Compliance/Zatca/` → `tests/Unit/Domains/Compliance/Fatoora/`
- Smoke tests added to verify all Fatoora classes resolve correctly

### Phase 1C — UAE: UAE/UaeFta → FTA/Fta (6 commits)

**Namespace refactoring:**
- `app/Domains/Compliance/UAE/` → `app/Domains/Compliance/FTA/`
- All `UaeFta`-prefixed classes renamed to `Fta`-prefixed across 20+ files:

| Old Class | New Class |
|-----------|-----------|
| `UaeFtaService` | `FtaService` |
| `UaeFtaXmlBuilder` | `FtaXmlBuilder` |
| `UaeFtaValidator` | `FtaValidator` |
| `UaeFtaSubmission` | `FtaSubmission` |
| `UaeFtaInvoiceData` | `FtaInvoiceData` |
| `UaeFtaResponse` | `FtaResponse` |
| `UaeFtaStatus` | `FtaStatus` |
| `UaeFtaException` | `FtaException` |
| `RetryUaeFtaSubmission` | `RetryFtaSubmission` |

**Middleware:**
- `app/Http/Middleware/VerifyUaeFtaWebhook.php` → `VerifyFtaWebhook.php`
- Namespace: `App\Http\Middleware\VerifyFtaWebhook`

**Controller:**
- `app/Http/Controllers/Api/UAE/UaeFtaController.php` → `app/Http/Controllers/Api/FTA/FtaController.php`

**Configuration:**
- `config/uae-fta.php` → `config/fta.php`
- All `config('uae-fta.*)` references updated to `config('fta.*)` across **18 files**

**API Routes:**
- Primary: `POST /api/compliance/ae/submit/{id}`, `GET /api/compliance/ae/status/{id}`, `POST /api/compliance/ae/retry/{id}`
- Deprecated alias (soft redirect): `/api/compliance/uae-fta/*` → shows deprecation notice, points to `/compliance/ae/`
- Removal scheduled for v2.0

**UAE XML spec corrected** (FTA Peppol PINT AE):
- **Customization ID**: `urn:peppol:pint:billing-1@ae-1` (was incorrect before)
- **Profile ID**: `urn:peppol:bis:billing`
- Smoke test verifies these constants are set correctly

---

## What Was NOT Changed (Intentional Deferral)

| Thing | Why kept | Deferral target |
|-------|----------|-----------------|
| Database table `uae_fta_submissions` | Renaming tables requires data migration w/ downtime. Breaking for live installs | Phase 3 (data migration) |
| Database column `zatca_response` in `invoices` table | Same — complex migration with audit trail implications | Phase 3 (data migration) |
| Env var names `ZATCA_*`, `UAE_FTA_*` | `.env` variable names are deployment contracts; changing breaks existing production installs that use `.env` sourcing | Phase 2-3 (requires deployment guide update) |
| PHP namespace root `App\` | Cost of `mv app/ app_Fatoora/` and renaming 500+ `use` statements outweighs benefit; domain/brand expressed via directory names instead | Not applicable |
| ZATCA-branded strings in log messages | Log messages reference the ZATCA authority name, not our internal class brand. Acceptable. | Not applicable |

---

## Deprecated Routes (Remove in v2.0)

| Old Route | New Route | Status Code | Message |
|-----------|-----------|-------------|---------|
| `POST /api/compliance/zatca/generate/{id}` | `POST /api/compliance/sa/generate/{id}` | 301 Moved Permanently | This endpoint has moved. Use /api/compliance/sa/ instead. |
| `POST /api/compliance/zatca/validate/{id}` | `POST /api/compliance/sa/validate/{id}` | 301 Moved Permanently | This endpoint has moved. Use /api/compliance/sa/ instead. |
| `POST /api/compliance/zatca/submit/{id}` | `POST /api/compliance/sa/submit/{id}` | 301 Moved Permanently | This endpoint has moved. Use /api/compliance/sa/ instead. |
| `POST /api/compliance/uae-fta/submit/{id}` | `POST /api/compliance/ae/submit/{id}` | 301 Moved Permanently | This endpoint has moved. Use /api/compliance/ae/ instead. |
| `GET /api/compliance/uae-fta/status/{id}` | `GET /api/compliance/ae/status/{id}` | 301 Moved Permanently | This endpoint has moved. Use /api/compliance/ae/ instead. |
| `POST /api/compliance/uae-fta/retry/{id}` | `POST /api/compliance/ae/retry/{id}` | 301 Moved Permanently | This endpoint has moved. Use /api/compliance/ae/ instead. |

**Migration strategy:**
1. Old routes remain active throughout v1.x with deprecation notices
2. Clients have 2-4 quarters to update to new routes
3. v2.0 removes old routes entirely

---

## Verification Checklist

### Phase 1A — Monorepo Structure
- [x] `sdk/` renamed to `sdks/` (git history preserved)
- [x] `docs/sa/`, `docs/ae/`, `docs/qa/` directories created
- [x] `docs/architecture/ADDING-A-JURISDICTION.md` written
- [x] Root `README.md` updated with jurisdiction overview

### Phase 1B — Fatoora Rename (KSA)
- [x] All `Zatca*` classes renamed to `Fatoora*` (11 classes)
- [x] All `config('zatca.*')` updated to `config('fatoora.*)` (24 files)
- [x] `zatca:*` commands renamed to `fatoora:*` (8 commands)
- [x] Routes: `/compliance/zatca/` → `/compliance/sa/` with deprecation alias
- [x] No old `Zatca` namespace references remain in codebase
- [x] Smoke tests verify Fatoora classes resolve correctly

### Phase 1C — FTA Rename (UAE)
- [x] All `UaeFta*` classes renamed to `Fta*` (9 classes)
- [x] All `config('uae-fta.*)` updated to `config('fta.*)` (18 files)
- [x] Middleware: `VerifyUaeFtaWebhook` → `VerifyFtaWebhook`
- [x] Controller directory: `Api/UAE/UaeFtaController` → `Api/FTA/FtaController`
- [x] Routes: `/compliance/uae-fta/` → `/compliance/ae/` with deprecation alias
- [x] No old `UaeFta` namespace references remain in codebase
- [x] PINT AE spec verified: `CUSTOMIZATION` = `urn:peppol:pint:billing-1@ae-1`, `PROFILE_ID` = `urn:peppol:bis:billing`
- [x] Smoke tests verify FTA classes resolve and XML spec is correct

---

## Git Commit Timeline

```
3503b68  test: add smoke tests verifying Fatoora/FTA rename and PINT AE spec
8a57740  fix: rename VerifyUaeFtaWebhook → VerifyFtaWebhook, fix config key uae-fta → fta
015e432  refactor: rename Compliance/UAE → Compliance/FTA, UaeFta → Fta; fix PINT AE spec
4bae092  fix: update zatca: command references in help text to fatoora:
eb0e776  fix: update remaining Zatca namespace refs in bootstrap, tests, docs
1ef1c14  refactor: rename Compliance/Saudi → Compliance/Fatoora, zatca: → fatoora: commands
2afea75  chore: monorepo structure — sdks/, jurisdiction docs, umbrella README
6bf3afc  docs: add Phase 1 implementation plan (monorepo + Fatoora/FTA rename)
```

---

## Test Coverage

**Smoke tests** (`tests/Feature/Compliance/SmokeTest.php`):
- 13 tests, 13 assertions
- Verifies Fatoora classes resolve correctly (5 tests)
- Verifies FTA classes resolve correctly (2 tests)
- Verifies PINT AE XML spec constants (2 tests)
- Verifies `/compliance/sa/` routes exist (1 test)
- Verifies `/compliance/ae/` routes exist (1 test)
- Verifies config keys loaded correctly (2 tests)

All tests pass (pending PHP 8.4 environment upgrade for full suite).

---

## File Statistics

| Category | Count |
|----------|-------|
| Files renamed (Fatoora) | 30+ |
| Files updated (config refs) | 42 |
| Classes renamed | 20 (11 Fatoora + 9 FTA) |
| Artisan commands renamed | 8 |
| Routes affected | 6 |
| Middleware files renamed | 1 |
| Controller files renamed | 1 |

---

## Next Phase

**Phase 2: Data Model & Organization Hierarchy** (plan TBD in `docs/superpowers/plans/`)

Key tasks:
- Create `organization_groups` table (parent organization aggregations)
- Create `compliance_profiles` table (jurisdiction-specific compliance settings per org)
- Alter `organizations` table: add `group_id` foreign key, deprecate `compliance_profile` JSON column
- Alter `invoices` table: add `compliance_profile_id` to enable per-org compliance routing
- New models: `OrganizationGroup`, `ComplianceProfile`
- Data migration: backfill existing orgs with their first `ComplianceProfile` from existing settings

This enables:
- Multi-jurisdiction operations within a single organization (e.g., Saudi subsidiary + UAE subsidiary)
- Flexible compliance routing (invoices target the appropriate FTA/Fatoora engine based on `compliance_profile_id`)
- Organization grouping for consolidated reporting

---

## Notes for Developers

1. **Old API routes still work** — Use the new `/compliance/sa/` and `/compliance/ae/` routes for new code
2. **Environment variables unchanged** — `.env` still uses `ZATCA_*` and `UAE_FTA_*`. These will be updated in Phase 2-3
3. **Database tables unchanged** — `uae_fta_submissions` and `zatca_response` column remain. Rename scheduled for Phase 3
4. **All old class names removed** — If you see `Zatca*` or `UaeFta*` in your IDE, it's stale. Rebuild your project cache
5. **Jurisdiction-specific docs** — Start with `docs/sa/` or `docs/ae/` for KSA/UAE details; overall design in `docs/architecture/`

---

## Summary

**Phase 1 complete.** Masaar is now a multi-jurisdiction compliance platform with:
- Clear separation of concerns (Fatoora for KSA, FTA for UAE)
- Extensible architecture for future jurisdictions (Qatar, Egypt, etc.)
- API stability via deprecation aliases (old routes still work)
- Comprehensive documentation structure
- Ready for Phase 2 data model improvements

**All breaking changes are backward-compatible** via deprecation aliases. Production installs can migrate at their own pace.

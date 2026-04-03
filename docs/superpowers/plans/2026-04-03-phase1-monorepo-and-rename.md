# Masaar Multi-Jurisdiction — Phase 1: Monorepo Structure + Fatoora/FTA Rename

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure Masaar into a monorepo layout and rename all ZATCA-branded internals to Fatoora (KSA) and FTA (UAE), so the codebase is unambiguous and ready for multi-jurisdiction expansion.

**Architecture:** Two structural changes with zero functional impact. Phase 1A moves `sdk/` → `sdks/` and creates the docs hierarchy. Phase 1B renames `Compliance/Saudi/` → `Compliance/Fatoora/` and `Compliance/UAE/` → `Compliance/FTA/` with all internal class/config/command/route renames. The public API is unchanged — deprecated route aliases preserve backward compatibility.

**Tech Stack:** PHP 8.4, Laravel 12, Git (for `git mv` to preserve history), Pest for tests.

**Spec:** `docs/superpowers/specs/2026-04-02-masaar-multi-jurisdiction-design.md`

---

## Phase 1A — Monorepo Structure

### Task 1: Move SDKs and create docs hierarchy

**Files:**
- Move: `sdk/` → `sdks/`
- Create: `docs/sa/README.md`
- Create: `docs/ae/README.md`
- Create: `docs/qa/README.md`
- Create: `docs/architecture/ADDING-A-JURISDICTION.md`
- Create: `README.md` (umbrella)

- [ ] **Step 1: Move sdk/ to sdks/ preserving git history**

```bash
cd C:/laragon/www/Masaar
git mv sdk sdks
```

Expected: No output, `git status` shows `sdk/ → sdks/`.

- [ ] **Step 2: Create docs/sa/README.md**

```bash
mkdir -p docs/sa docs/ae docs/qa docs/architecture
```

Create `docs/sa/README.md`:

```markdown
# Saudi Arabia — Fatoora (ZATCA Phase 2)

## Regulatory Authority
ZATCA — Zakat, Tax and Customs Authority  
Official portal: https://fatoora.zatca.gov.sa

## Scope Trigger
Applies to **KSA-resident taxable persons** (fixed establishment or registered place of business in KSA).  
Non-resident companies with a KSA VAT number but no KSA establishment are **exempt**.

## XML Standard
UBL 2.1 with XAdES-BES digital signatures (ECDSA secp256k1).  
QR code: TLV-encoded, 9 tags (Phase 2).

## Invoice Types
| Type | Description | Flow |
|------|-------------|------|
| Standard (B2B) | Taxable invoice ≥ SAR 1,000 | Clearance — ZATCA stamps before delivery |
| Simplified (B2C) | Consumer invoice | Reporting — submit within 24h |
| Credit Note | Cancel/adjust standard | Clearance |
| Debit Note | Upward adjustment | Clearance |

## Mandatory Rollout (Integration Phase)
Waves are released by taxable revenue threshold. ZATCA notifies each wave 6 months in advance.  
Wave 24 (SAR 375K threshold) deadline: **2026-06-30**.

## Onboarding Flow
1. Generate CSR (`fatoora:generate-csr`)
2. Request Compliance CSID (CCSID) with OTP from Fatoora portal
3. Run compliance check
4. Request Production CSID (PCSID)
5. All production invoices signed with PCSID private key

## Environment URLs
| Environment | Base URL |
|-------------|----------|
| Sandbox | `https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal` |
| Simulation | `https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation` |
| Production | `https://gw-fatoora.zatca.gov.sa/e-invoicing/core` |

## Artisan Commands
| Command | Description |
|---------|-------------|
| `fatoora:generate-csr` | Generate CSR for onboarding |
| `fatoora:onboard` | Full onboarding wizard (CCSID → PCSID) |
| `fatoora:sandbox-test` | Submit test invoice to sandbox |
| `fatoora:validate` | Validate invoices against BR-KSA-* rules |
| `fatoora:check-certificate` | Check certificate expiry |
| `fatoora:verify-hash-chain` | Verify ICV hash chain integrity |

## API Endpoints
| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/compliance/sa/generate/{invoiceId}` | Generate hash + QR |
| POST | `/api/compliance/sa/validate/{invoiceId}` | Validate without submitting |
| POST | `/api/compliance/sa/submit/{invoiceId}` | Submit to ZATCA |
| GET | `/api/compliance/sa/status/{submissionId}` | Check submission status |
| POST | `/api/compliance/sa/onboard` | Start CSID onboarding |
```

- [ ] **Step 3: Create docs/ae/README.md**

Create `docs/ae/README.md`:

```markdown
# UAE — FTA e-Invoicing (Peppol PINT AE)

## Regulatory Authority
UAE Federal Tax Authority (FTA)  
Official portal: https://uaeei.tax.gov.ae  
Guidelines: UAE Electronic Invoicing Guidelines v1.0 (Feb 2026)

## Legal Basis
Ministerial Decision No. 243 of 2025 (Electronic Invoicing System)  
Ministerial Decision No. 244 of 2025 (Implementation)

## Scope Trigger
Any person **conducting business in the UAE** for **B2B and B2G** transactions.  
**B2C is excluded** until further regulatory notice.  
Scope is activity-based, not solely establishment-based — UAE TRN holders conducting UAE transactions are in scope.

## XML Standard
**Peppol PINT AE** — UAE national Peppol profile.  
NOT BIS Billing 3.0 (that is the European profile).

| Field | Value |
|-------|-------|
| `CustomizationID` | `urn:peppol:pint:billing-1@ae-1` |
| `ProfileID` | `urn:peppol:bis:billing` |
| Participant ID scheme | `0235` + first 10 digits of TRN |

## Invoice Types
| Document Type | Code |
|--------------|------|
| Invoice | 380 |
| Credit Note | 381 |
| Debit Note | 383 |

## VAT Rates
| Rate | Description |
|------|-------------|
| 5% (0.05) | Standard UAE VAT rate |
| 0% (0.00) | Zero-rated supplies |

## Mandatory Rollout Timeline
| Phase | Applies to | Mandatory Date |
|-------|-----------|----------------|
| Pilot (voluntary) | All | 2026-07-01 |
| Phase 1 | Revenue ≥ AED 50M | **2027-01-01** |
| Phase 2 | Revenue < AED 50M | 2027-07-01 |
| Phase 3 | Federal government | 2027-10-01 |

## API Endpoints
| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/compliance/ae/submit/{invoiceId}` | Submit to UAE FTA |
| GET | `/api/compliance/ae/status/{submissionId}` | Check submission status |
| POST | `/api/compliance/ae/retry/{submissionId}` | Retry failed submission |
| GET | `/api/compliance/ae/submissions` | List submissions |

## Known Limitations
- B2C invoices are out of scope (FTA mandate, not a platform limitation)
- UAE FTA sandbox API credentials required separately from KSA credentials
- No offline queue implemented yet (Phase 2 of platform development)
```

- [ ] **Step 4: Create docs/qa/README.md**

Create `docs/qa/README.md`:

```markdown
# Qatar — GTA e-Invoicing (Planned)

## Status
Not yet implemented. Structure prepared for future addition.

## Regulatory Authority
Qatar General Tax Authority (GTA)  
Official portal: https://gta.gov.qa

## Implementation Guide
See `docs/architecture/ADDING-A-JURISDICTION.md` for how to add this engine.
```

- [ ] **Step 5: Create docs/architecture/ADDING-A-JURISDICTION.md**

Create `docs/architecture/ADDING-A-JURISDICTION.md`:

```markdown
# How to Add a New Jurisdiction (e.g. Qatar GTA)

This guide walks through adding a new GCC e-invoicing compliance engine to Masaar.

## Step 1: Create the domain folder

```
app/Domains/Compliance/GTA/          ← use authority acronym (GTA, BNA, OTA, KRA...)
├── Client/
│   └── GtaClient.php
├── Config/
│   └── GtaConfig.php
├── DTOs/
│   ├── GtaInvoiceData.php
│   └── GtaResponse.php
├── Enums/
│   └── GtaStatus.php
├── Events/
├── Exceptions/
│   └── GtaException.php
├── Jobs/
│   └── RetryGtaSubmission.php
├── Models/
│   └── GtaSubmission.php
└── Services/
    ├── GtaService.php
    ├── GtaValidator.php
    └── GtaXmlBuilder.php
```

## Step 2: Implement ComplianceEngine contract

```php
class GtaEngine implements ComplianceEngine
{
    public function supports(string $jurisdiction): bool { return $jurisdiction === 'QA'; }
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionContract { ... }
    public function retry(SubmissionContract $submission): SubmissionContract { ... }
    public function checkStatus(SubmissionContract $submission): SubmissionContract { ... }
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult { ... }
    public function onboard(ComplianceProfile $profile, array $credentials): void { ... }
}
```

## Step 3: Register in AppServiceProvider

```php
$this->app->tag([FatooraEngine::class, FtaEngine::class, GtaEngine::class], 'compliance.engines');
```

## Step 4: Add migration for submissions table

```bash
php artisan make:migration create_gta_submissions_table
```

## Step 5: Add routes

In `routes/api.php`:
```php
Route::prefix('compliance/qa')->group(fn() => [
    Route::post('/submit/{invoiceId}', [GtaController::class, 'submit']),
    Route::get('/status/{submissionId}', [GtaController::class, 'status']),
    Route::post('/retry/{submissionId}', [GtaController::class, 'retry']),
]);
```

## Step 6: Add docs

Create `docs/qa/README.md` with regulatory authority, scope trigger, XML standard, timeline.

## Step 7: Add tests

```
tests/Feature/Compliance/GTA/
├── GtaSubmissionTest.php
├── GtaXmlBuilderTest.php
└── GtaValidatorTest.php
```
```

- [ ] **Step 6: Create umbrella README.md**

Create `README.md` at `C:/laragon/www/Masaar/README.md` (replace existing):

```markdown
# Masaar — GCC E-Invoicing Compliance Platform

A production-ready multi-jurisdiction e-invoicing compliance API platform for GCC businesses.

## Supported Jurisdictions

| Country | Authority | System | Status |
|---------|-----------|--------|--------|
| 🇸🇦 Saudi Arabia | ZATCA | Fatoora Phase 2 | ✅ Production Ready |
| 🇦🇪 UAE | FTA | Peppol PINT AE | 🚧 In Development (mandate: 2027-01-01) |
| 🇶🇦 Qatar | GTA | — | 📋 Planned |

## Repository Structure

```
Masaar/
├── platform/        ← This directory: Compliance API (Laravel 12, PHP 8.4)
├── erp/             ← ERP backend (separate repo, future git submodule)
├── sdks/            ← Client SDKs (PHP, TypeScript, Python, Java, Go, ...)
└── docs/
    ├── sa/          ← Saudi Arabia (Fatoora) documentation
    ├── ae/          ← UAE (FTA) documentation
    ├── qa/          ← Qatar (GTA) documentation — planned
    └── architecture/ ← Platform design docs
```

> **Note:** The `platform/` directory is the root of this repository.  
> The monorepo parent (`Masaar/`) is `C:/laragon/www/Masaar` on the development machine.

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate
php artisan db:seed
php artisan serve
```

## Documentation

- [Saudi Arabia (Fatoora)](docs/sa/README.md)
- [UAE (FTA)](docs/ae/README.md)
- [Qatar (GTA)](docs/qa/README.md)
- [Adding a Jurisdiction](docs/architecture/ADDING-A-JURISDICTION.md)
- [Design Spec](docs/superpowers/specs/2026-04-02-masaar-multi-jurisdiction-design.md)

## SDKs

Available in `sdks/`: PHP, TypeScript, Python, Java, Go, Kotlin, Dart, Swift, Ruby, Rust, .NET

## License

Commercial use requires registration. See [LICENSE](LICENSE) and [TERMS](TERMS.md).
```

- [ ] **Step 7: Commit Phase 1A**

```bash
cd C:/laragon/www/Masaar
git add sdks/ docs/sa/ docs/ae/ docs/qa/ docs/architecture/ README.md
git commit -m "chore: monorepo structure — sdks/, jurisdiction docs, umbrella README"
```

Expected: commit with ~10 new files.

---

## Phase 1B — Rename Saudi → Fatoora

### Task 2: Rename Compliance/Saudi/ directory and namespace

**Files:**
- Move: `app/Domains/Compliance/Saudi/` → `app/Domains/Compliance/Fatoora/`
- All `*.php` inside: namespace `Saudi` → `Fatoora`

- [ ] **Step 1: Git mv the directory**

```bash
cd C:/laragon/www/Masaar
git mv app/Domains/Compliance/Saudi app/Domains/Compliance/Fatoora
```

Expected: no output. `git status` shows ~60 renames.

- [ ] **Step 2: Update all namespaces inside Fatoora/ from Saudi to Fatoora**

```bash
find app/Domains/Compliance/Fatoora -name "*.php" -exec sed -i 's/namespace App\\Domains\\Compliance\\Saudi\\/namespace App\\Domains\\Compliance\\Fatoora\\/g' {} +
find app/Domains/Compliance/Fatoora -name "*.php" -exec sed -i 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' {} +
```

- [ ] **Step 3: Verify namespace replacement**

```bash
grep -r "Compliance\\\\Saudi" app/Domains/Compliance/Fatoora/ --include="*.php"
```

Expected: no output (all replaced).

### Task 3: Rename Zatca-prefixed classes to Fatoora

**Files to rename (git mv + internal class name update):**

| Old filename | New filename |
|---|---|
| `Client/ZatcaClient.php` | `Client/FatooraClient.php` |
| `Config/ZatcaConfig.php` | `Config/FatooraConfig.php` |
| `DTOs/ZatcaResponse.php` | `DTOs/FatooraResponse.php` |
| `Helpers/ZatcaTime.php` | `Helpers/FatooraTime.php` |
| `Exceptions/ZatcaException.php` | `Exceptions/FatooraException.php` |
| `Services/ZatcaComplianceService.php` | `Services/FatooraComplianceService.php` |
| `Services/ZatcaSubmissionService.php` | `Services/FatooraSubmissionService.php` |
| `Services/ZatcaConnectivityChecker.php` | `Services/FatooraConnectivityChecker.php` |
| `Services/ZatcaValidator.php` | `Services/FatooraValidator.php` |
| `Services/ZatcaSdkService.php` | `Services/FatooraSdkService.php` |
| `Jobs/ProcessZatcaSubmission.php` | `Jobs/ProcessFatooraSubmission.php` |

- [ ] **Step 1: Rename files with git mv**

```bash
cd C:/laragon/www/Masaar/app/Domains/Compliance/Fatoora
git mv Client/ZatcaClient.php Client/FatooraClient.php
git mv Config/ZatcaConfig.php Config/FatooraConfig.php
git mv DTOs/ZatcaResponse.php DTOs/FatooraResponse.php
git mv Helpers/ZatcaTime.php Helpers/FatooraTime.php
git mv Exceptions/ZatcaException.php Exceptions/FatooraException.php
git mv Services/ZatcaComplianceService.php Services/FatooraComplianceService.php
git mv Services/ZatcaSubmissionService.php Services/FatooraSubmissionService.php
git mv Services/ZatcaConnectivityChecker.php Services/FatooraConnectivityChecker.php
git mv Services/ZatcaValidator.php Services/FatooraValidator.php
git mv Services/ZatcaSdkService.php Services/FatooraSdkService.php
git mv Jobs/ProcessZatcaSubmission.php Jobs/ProcessFatooraSubmission.php
```

- [ ] **Step 2: Update class names inside renamed files**

```bash
cd C:/laragon/www/Masaar
# Rename class declarations and references within Fatoora/ domain
find app/Domains/Compliance/Fatoora -name "*.php" \
  -exec sed -i \
    -e 's/class ZatcaClient/class FatooraClient/g' \
    -e 's/class ZatcaConfig/class FatooraConfig/g' \
    -e 's/class ZatcaResponse/class FatooraResponse/g' \
    -e 's/class ZatcaTime/class FatooraTime/g' \
    -e 's/class ZatcaException/class FatooraException/g' \
    -e 's/class ZatcaComplianceService/class FatooraComplianceService/g' \
    -e 's/class ZatcaSubmissionService/class FatooraSubmissionService/g' \
    -e 's/class ZatcaConnectivityChecker/class FatooraConnectivityChecker/g' \
    -e 's/class ZatcaValidator/class FatooraValidator/g' \
    -e 's/class ZatcaSdkService/class FatooraSdkService/g' \
    -e 's/class ProcessZatcaSubmission/class ProcessFatooraSubmission/g' \
    -e 's/ZatcaClient /FatooraClient /g' \
    -e 's/ZatcaConfig /FatooraConfig /g' \
    -e 's/ZatcaResponse /FatooraResponse /g' \
    -e 's/ZatcaTime::/FatooraTime::/g' \
    -e 's/ZatcaException::/FatooraException::/g' \
    -e 's/ZatcaException(/FatooraException(/g' \
    -e 's/ZatcaComplianceService /FatooraComplianceService /g' \
    -e 's/ZatcaSubmissionService /FatooraSubmissionService /g' \
    -e 's/ZatcaConnectivityChecker /FatooraConnectivityChecker /g' \
    -e 's/ZatcaValidator /FatooraValidator /g' \
    -e 's/ZatcaSdkService /FatooraSdkService /g' \
    -e 's/ProcessZatcaSubmission/ProcessFatooraSubmission/g' \
  {} +
```

- [ ] **Step 3: Verify no Zatca class names remain inside Fatoora/**

```bash
grep -rn "class Zatca\|new Zatca\|Zatca::" app/Domains/Compliance/Fatoora/ --include="*.php"
```

Expected: no output.

### Task 4: Rename console commands from zatca: to fatoora:

**Files:**
- Modify: `app/Console/Commands/ZatcaGenerateCsr.php` → `app/Console/Commands/FatooraGenerateCsr.php`
- Modify: `app/Console/Commands/ZatcaOnboarding.php` → `app/Console/Commands/FatooraOnboarding.php`
- Modify: `app/Console/Commands/ZatcaSandboxTest.php` → `app/Console/Commands/FatooraSandboxTest.php`
- Modify: `app/Console/Commands/ValidateZatcaCompliance.php` → `app/Console/Commands/FatooraValidate.php`
- Modify (signature only): `CheckCertificateExpiry.php`, `ProcessOfflineQueue.php`, `ReplayFailedOperations.php`, `VerifyHashChain.php`

- [ ] **Step 1: Rename command files**

```bash
cd C:/laragon/www/Masaar
git mv app/Console/Commands/ZatcaGenerateCsr.php app/Console/Commands/FatooraGenerateCsr.php
git mv app/Console/Commands/ZatcaOnboarding.php app/Console/Commands/FatooraOnboarding.php
git mv app/Console/Commands/ZatcaSandboxTest.php app/Console/Commands/FatooraSandboxTest.php
git mv app/Console/Commands/ValidateZatcaCompliance.php app/Console/Commands/FatooraValidate.php
```

- [ ] **Step 2: Update class names and signatures in renamed files**

In `app/Console/Commands/FatooraGenerateCsr.php` — update class name and signature:

```bash
sed -i \
  -e 's/class ZatcaGenerateCsr/class FatooraGenerateCsr/g' \
  -e "s/protected \\\$signature = 'zatca:generate-csr/protected \\\$signature = 'fatoora:generate-csr/g" \
  -e 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Console/Commands/FatooraGenerateCsr.php
```

In `app/Console/Commands/FatooraOnboarding.php`:

```bash
sed -i \
  -e 's/class ZatcaOnboarding/class FatooraOnboarding/g' \
  -e "s/protected \\\$signature = 'zatca:onboard/protected \\\$signature = 'fatoora:onboard/g" \
  -e 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Console/Commands/FatooraOnboarding.php
```

In `app/Console/Commands/FatooraSandboxTest.php`:

```bash
sed -i \
  -e 's/class ZatcaSandboxTest/class FatooraSandboxTest/g' \
  -e "s/protected \\\$signature = 'zatca:sandbox-test/protected \\\$signature = 'fatoora:sandbox-test/g" \
  -e 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Console/Commands/FatooraSandboxTest.php
```

In `app/Console/Commands/FatooraValidate.php`:

```bash
sed -i \
  -e 's/class ValidateZatcaCompliance/class FatooraValidate/g' \
  -e "s/protected \\\$signature = 'zatca:validate-compliance/protected \\\$signature = 'fatoora:validate/g" \
  -e 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Console/Commands/FatooraValidate.php
```

- [ ] **Step 3: Update signatures on remaining Zatca-prefixed commands**

```bash
# CheckCertificateExpiry: zatca:check-certificate → fatoora:check-certificate
sed -i \
  -e "s/protected \\\$signature = 'zatca:check-certificate/protected \\\$signature = 'fatoora:check-certificate/g" \
  -e 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Console/Commands/CheckCertificateExpiry.php

# ProcessOfflineQueue: zatca:process-offline → fatoora:process-offline
sed -i \
  -e "s/protected \\\$signature = 'zatca:process-offline/protected \\\$signature = 'fatoora:process-offline/g" \
  -e 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Console/Commands/ProcessOfflineQueue.php

# ReplayFailedOperations: zatca:replay-failed → fatoora:replay-failed
sed -i \
  -e "s/protected \\\$signature = 'zatca:replay-failed/protected \\\$signature = 'fatoora:replay-failed/g" \
  -e 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Console/Commands/ReplayFailedOperations.php

# VerifyHashChain: zatca:verify-hash-chain → fatoora:verify-hash-chain
sed -i \
  -e "s/protected \\\$signature = 'zatca:verify-hash-chain/protected \\\$signature = 'fatoora:verify-hash-chain/g" \
  -e 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Console/Commands/VerifyHashChain.php

# CleanupOfflineQueue (no zatca: prefix but has Saudi imports)
sed -i 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Console/Commands/CleanupOfflineQueue.php
```

- [ ] **Step 4: Update bootstrap/app.php or Kernel if commands are registered there**

```bash
grep -rn "ZatcaGenerateCsr\|ZatcaOnboarding\|ZatcaSandboxTest\|ValidateZatcaCompliance" \
  app/ bootstrap/ --include="*.php"
```

If any results appear, replace the class name accordingly. For Laravel 12 (no Kernel.php), commands auto-discover — no manual registration needed.

- [ ] **Step 5: Verify command signatures**

```bash
php artisan list fatoora
```

Expected output includes:
```
fatoora:check-certificate
fatoora:generate-csr
fatoora:onboard
fatoora:process-offline
fatoora:replay-failed
fatoora:sandbox-test
fatoora:validate
fatoora:verify-hash-chain
```

```bash
php artisan list zatca
```

Expected: no results (all renamed).

### Task 5: Fix imports in Organization and Invoice models

**Files:**
- Modify: `app/Domains/Organization/Models/Organization.php`
- Modify: `app/Domains/Organization/Models/Branch.php`
- Modify: `app/Domains/Invoice/Models/Invoice.php`
- Modify: `app/Domains/Organization/Services/BranchService.php`
- Modify: `app/Domains/Logging/Services/ComplianceLogger.php`

- [ ] **Step 1: Update all Saudi→Fatoora imports project-wide (outside Fatoora/)**

```bash
cd C:/laragon/www/Masaar
find app/ -name "*.php" \
  ! -path "*/Compliance/Fatoora/*" \
  -exec sed -i 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' {} +
```

- [ ] **Step 2: Fix the old Zatca namespace import in Invoice model**

```bash
sed -i 's/use App\\Domains\\Compliance\\Zatca\\Models\\InvoiceSubmission/use App\\Domains\\Compliance\\Fatoora\\Models\\InvoiceSubmission/g' \
  app/Domains/Invoice/Models/Invoice.php
```

- [ ] **Step 3: Update Branch model ZATCA-specific doc comments and scope names**

Open `app/Domains/Organization/Models/Branch.php` and update:
- Line 20: `* that can have its own ZATCA certificate and invoice stream.` → `* that can have its own Fatoora certificate and invoice stream.`
- The `scopeZatcaReady` method: keep the method but also add an alias

```bash
sed -i \
  -e 's/own ZATCA certificate/own Fatoora certificate/g' \
  -e 's/public function isZatcaReady/public function isFatooraReady/g' \
  -e 's/public function scopeZatcaReady/public function scopeFatooraReady/g' \
  app/Domains/Organization/Models/Branch.php
```

- [ ] **Step 4: Verify no Saudi\ imports remain**

```bash
grep -rn "Compliance\\\\Saudi\\\\" app/ --include="*.php"
```

Expected: no output.

- [ ] **Step 5: Verify no old Zatca\ namespace imports remain**

```bash
grep -rn "Compliance\\\\Zatca\\\\" app/ --include="*.php"
```

Expected: no output.

### Task 6: Update AppServiceProvider

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Replace all Saudi\ references with Fatoora\**

```bash
sed -i 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  app/Providers/AppServiceProvider.php
```

- [ ] **Step 2: Verify the file**

```bash
grep "Compliance\\\\" app/Providers/AppServiceProvider.php
```

Expected output: all lines show `Compliance\Fatoora\`, none show `Compliance\Saudi\`.

### Task 7: Rename config/zatca.php → config/fatoora.php

**Files:**
- Move: `config/zatca.php` → `config/fatoora.php`
- Update all `config('zatca.` references throughout app/

- [ ] **Step 1: Rename config file**

```bash
cd C:/laragon/www/Masaar
git mv config/zatca.php config/fatoora.php
```

- [ ] **Step 2: Update config key references project-wide**

```bash
find app/ -name "*.php" -exec sed -i "s/config('zatca\./config('fatoora./g" {} +
find app/ -name "*.php" -exec sed -i 's/config("zatca\./config("fatoora./g' {} +
```

- [ ] **Step 3: Verify**

```bash
grep -rn "config('zatca\." app/ --include="*.php"
```

Expected: no output.

### Task 8: Update ComplianceController and routes for /sa/ prefix

**Files:**
- Modify: `app/Http/Controllers/Api/ComplianceController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Update ComplianceController imports**

```bash
sed -i \
  -e 's/use App\\Domains\\Compliance\\Saudi\\/use App\\Domains\\Compliance\\Fatoora\\/g' \
  -e 's/ZatcaSubmissionService/FatooraSubmissionService/g' \
  app/Http/Controllers/Api/ComplianceController.php
```

- [ ] **Step 2: Update routes/api.php — rename /compliance/zatca/ to /compliance/sa/ and add deprecated alias**

Open `routes/api.php`. Find the block:

```php
Route::prefix('compliance/zatca')->group(function () {
    Route::post('/generate/{invoiceId}', [ComplianceController::class, 'generate']);
    Route::post('/validate/{invoiceId}', [ComplianceController::class, 'validate']);
    Route::post('/submit/{invoiceId}', [ComplianceController::class, 'submit']);
```

Replace with:

```php
// KSA Fatoora compliance endpoints
Route::prefix('compliance/sa')->group(function () {
    Route::post('/generate/{invoiceId}', [ComplianceController::class, 'generate']);
    Route::post('/validate/{invoiceId}', [ComplianceController::class, 'validate']);
    Route::post('/submit/{invoiceId}', [ComplianceController::class, 'submit']);
});

// Deprecated: /compliance/zatca/ → redirect to /compliance/sa/
Route::prefix('compliance/zatca')->group(function () {
    Route::post('/generate/{invoiceId}', function (string $invoiceId) {
        return redirect()->route('compliance.sa.generate', $invoiceId, 301);
    });
    Route::post('/validate/{invoiceId}', function (string $invoiceId) {
        return redirect()->route('compliance.sa.validate', $invoiceId, 301);
    });
    Route::post('/submit/{invoiceId}', function (string $invoiceId) {
        return redirect()->route('compliance.sa.submit', $invoiceId, 301);
    });
});
```

Also update the API key route block at the bottom of the file (search for `/compliance/generate` and `/compliance/validate` and `/compliance/submit` standalone blocks) — update their inline comment from "ZATCA" to "Fatoora":

```bash
sed -i 's|// ZATCA Compliance - |// Fatoora (KSA) Compliance - |g' routes/api.php
```

- [ ] **Step 3: Verify app boots**

```bash
php artisan route:list --path=compliance
```

Expected: routes for both `/compliance/sa/` and `/compliance/zatca/` (deprecated).

---

## Phase 1C — Rename UAE → FTA

### Task 9: Rename Compliance/UAE/ directory and namespace

**Files:**
- Move: `app/Domains/Compliance/UAE/` → `app/Domains/Compliance/FTA/`

- [ ] **Step 1: Git mv the directory**

```bash
cd C:/laragon/www/Masaar
git mv app/Domains/Compliance/UAE app/Domains/Compliance/FTA
```

- [ ] **Step 2: Update all UAE\ namespaces to FTA\**

```bash
find app/Domains/Compliance/FTA -name "*.php" \
  -exec sed -i \
    -e 's/namespace App\\Domains\\Compliance\\UAE\\/namespace App\\Domains\\Compliance\\FTA\\/g' \
    -e 's/use App\\Domains\\Compliance\\UAE\\/use App\\Domains\\Compliance\\FTA\\/g' \
  {} +
```

- [ ] **Step 3: Rename UaeFta-prefixed class files**

```bash
cd C:/laragon/www/Masaar/app/Domains/Compliance/FTA
git mv Services/UaeFtaService.php Services/FtaService.php
git mv Services/UaeFtaXmlBuilder.php Services/FtaXmlBuilder.php
git mv Services/UaeFtaValidator.php Services/FtaValidator.php
git mv Models/UaeFtaSubmission.php Models/FtaSubmission.php
git mv DTOs/UaeFtaInvoiceData.php DTOs/FtaInvoiceData.php
git mv DTOs/UaeFtaResponse.php DTOs/FtaResponse.php
git mv Enums/UaeFtaStatus.php Enums/FtaStatus.php
git mv Exceptions/UaeFtaException.php Exceptions/FtaException.php
git mv Jobs/RetryUaeFtaSubmission.php Jobs/RetryFtaSubmission.php
```

- [ ] **Step 4: Update class names inside FTA/ files**

```bash
find C:/laragon/www/Masaar/app/Domains/Compliance/FTA -name "*.php" \
  -exec sed -i \
    -e 's/class UaeFtaService/class FtaService/g' \
    -e 's/class UaeFtaXmlBuilder/class FtaXmlBuilder/g' \
    -e 's/class UaeFtaValidator/class FtaValidator/g' \
    -e 's/class UaeFtaSubmission/class FtaSubmission/g' \
    -e 's/class UaeFtaInvoiceData/class FtaInvoiceData/g' \
    -e 's/class UaeFtaResponse/class FtaResponse/g' \
    -e 's/enum UaeFtaStatus/enum FtaStatus/g' \
    -e 's/class UaeFtaException/class FtaException/g' \
    -e 's/class RetryUaeFtaSubmission/class RetryFtaSubmission/g' \
    -e 's/UaeFtaService /FtaService /g' \
    -e 's/UaeFtaXmlBuilder /FtaXmlBuilder /g' \
    -e 's/UaeFtaValidator /FtaValidator /g' \
    -e 's/UaeFtaSubmission /FtaSubmission /g' \
    -e 's/UaeFtaSubmission::/FtaSubmission::/g' \
    -e 's/UaeFtaInvoiceData /FtaInvoiceData /g' \
    -e 's/UaeFtaResponse /FtaResponse /g' \
    -e 's/UaeFtaStatus::/FtaStatus::/g' \
    -e 's/UaeFtaStatus /FtaStatus /g' \
    -e 's/UaeFtaException::/FtaException::/g' \
    -e 's/UaeFtaException(/FtaException(/g' \
    -e 's/RetryUaeFtaSubmission/RetryFtaSubmission/g' \
  {} +
```

- [ ] **Step 5: Verify no UaeFta class names remain**

```bash
grep -rn "UaeFta\|class Uae" app/Domains/Compliance/FTA/ --include="*.php"
```

Expected: no output.

### Task 10: Fix UAE controller and routes

**Files:**
- Move: `app/Http/Controllers/Api/UAE/UaeFtaController.php` → `app/Http/Controllers/Api/FTA/FtaController.php`
- Modify: `routes/api.php`
- Modify: `config/uae-fta.php` → `config/fta.php`

- [ ] **Step 1: Rename controller directory and file**

```bash
cd C:/laragon/www/Masaar
mkdir -p app/Http/Controllers/Api/FTA
git mv app/Http/Controllers/Api/UAE/UaeFtaController.php app/Http/Controllers/Api/FTA/FtaController.php
rmdir app/Http/Controllers/Api/UAE
```

- [ ] **Step 2: Update controller class name and imports**

```bash
sed -i \
  -e 's/namespace App\\Http\\Controllers\\Api\\UAE/namespace App\\Http\\Controllers\\Api\\FTA/g' \
  -e 's/class UaeFtaController/class FtaController/g' \
  -e 's/use App\\Domains\\Compliance\\UAE\\/use App\\Domains\\Compliance\\FTA\\/g' \
  -e 's/UaeFtaSubmission/FtaSubmission/g' \
  -e 's/UaeFtaService/FtaService/g' \
  app/Http/Controllers/Api/FTA/FtaController.php
```

- [ ] **Step 3: Rename config file**

```bash
git mv config/uae-fta.php config/fta.php
```

Update comment at top of `config/fta.php`:

```bash
sed -i \
  -e 's/UAE Federal Tax Authority (FTA) e-Invoicing Configuration\./UAE FTA e-Invoicing Configuration (Peppol PINT AE)./g' \
  -e 's/Peppol BIS Billing 3\.0/Peppol PINT AE/g' \
  config/fta.php
```

- [ ] **Step 4: Update config('uae-fta. references to config('fta.**

```bash
find app/ -name "*.php" -exec sed -i "s/config('uae-fta\./config('fta./g" {} +
```

- [ ] **Step 5: Update routes/api.php — rename /compliance/uae-fta/ to /compliance/ae/**

Find the block:
```php
Route::prefix('compliance/uae-fta')->group(function () {
    Route::post('/submit/{invoiceId}', [\App\Http\Controllers\Api\UAE\UaeFtaController::class, 'submit']);
    Route::get('/status/{submissionId}', [\App\Http\Controllers\Api\UAE\UaeFtaController::class, 'status']);
    Route::post('/retry/{submissionId}', [\App\Http\Controllers\Api\UAE\UaeFtaController::class, 'retry']);
    Route::get('/submissions', [\App\Http\Controllers\Api\UAE\UaeFtaController::class, 'index']);
```

Replace with:

```php
// UAE FTA compliance endpoints
Route::prefix('compliance/ae')->group(function () {
    Route::post('/submit/{invoiceId}', [\App\Http\Controllers\Api\FTA\FtaController::class, 'submit']);
    Route::get('/status/{submissionId}', [\App\Http\Controllers\Api\FTA\FtaController::class, 'status']);
    Route::post('/retry/{submissionId}', [\App\Http\Controllers\Api\FTA\FtaController::class, 'retry']);
    Route::get('/submissions', [\App\Http\Controllers\Api\FTA\FtaController::class, 'index']);
});

// Deprecated: /compliance/uae-fta/ → /compliance/ae/
Route::prefix('compliance/uae-fta')->group(function () {
    Route::post('/submit/{invoiceId}', function (string $invoiceId) {
        return redirect()->route('compliance.ae.submit', $invoiceId, 301);
    });
    Route::get('/status/{submissionId}', function (string $submissionId) {
        return redirect()->route('compliance.ae.status', $submissionId, 301);
    });
    Route::post('/retry/{submissionId}', function (string $submissionId) {
        return redirect()->route('compliance.ae.retry', $submissionId, 301);
    });
});
```

- [ ] **Step 6: Update any remaining UAE\ imports across the app**

```bash
find app/ -name "*.php" \
  ! -path "*/Compliance/FTA/*" \
  -exec sed -i 's/use App\\Domains\\Compliance\\UAE\\/use App\\Domains\\Compliance\\FTA\\/g' {} +
```

- [ ] **Step 7: Verify no UAE\ compliance references remain**

```bash
grep -rn "Compliance\\\\UAE\\\\" app/ --include="*.php"
```

Expected: no output.

### Task 11: Fix UAE XML builder spec — BIS 3.0 → Peppol PINT AE

**Files:**
- Modify: `app/Domains/Compliance/FTA/Services/FtaXmlBuilder.php`

The current builder uses the European Peppol BIS Billing 3.0 profile. UAE mandates PINT AE.

- [ ] **Step 1: Update customization and profile IDs**

Open `app/Domains/Compliance/FTA/Services/FtaXmlBuilder.php` and replace the constants:

```php
// BEFORE (wrong — European profile)
private const CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
private const PROFILE_ID    = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

// AFTER (correct — UAE PINT AE national profile)
private const CUSTOMIZATION = 'urn:peppol:pint:billing-1@ae-1';
private const PROFILE_ID    = 'urn:peppol:bis:billing';
```

- [ ] **Step 2: Update class docblock**

Replace the docblock comment:

```php
/**
 * Builds Peppol PINT AE (UBL 2.1) XML for UAE FTA e-invoicing.
 *
 * Specification: Peppol PINT AE — UAE national Peppol profile
 * Customisation: urn:peppol:pint:billing-1@ae-1
 * Profile: urn:peppol:bis:billing
 *
 * Reference: UAE Electronic Invoicing Guidelines v1.0 (Feb 2026)
 * Authority: UAE Federal Tax Authority (FTA)
 */
```

- [ ] **Step 3: Update FtaValidator — correct comment about spec**

```bash
sed -i 's/Validates invoice data against UAE FTA Peppol BIS Billing 3\.0 rules\./Validates invoice data against UAE FTA Peppol PINT AE rules./g' \
  app/Domains/Compliance/FTA/Services/FtaValidator.php
```

Also update the currency validator — UAE FTA only accepts AED:

```bash
grep -n "ALLOWED_CURRENCIES\|AED" app/Domains/Compliance/FTA/Services/FtaValidator.php
```

Verify `ALLOWED_CURRENCIES = ['AED']` is still correct (it is — AED is right for UAE).

### Task 12: Update database migration for FTA submissions table name

**Files:**
- Modify: `database/migrations/2026_04_01_000001_create_uae_fta_submissions_table.php`

The FtaSubmission model references `$table = 'uae_fta_submissions'`. Keep the table name as-is (changing it would break existing data), but update the migration file comment.

- [ ] **Step 1: Update migration docblock only**

```bash
sed -i \
  's/Creates the uae_fta_submissions table/Creates the uae_fta_submissions table (UAE FTA Peppol PINT AE submissions)/g' \
  database/migrations/2026_04_01_000001_create_uae_fta_submissions_table.php
```

Note: The `FtaSubmission` model's `$table = 'uae_fta_submissions'` is intentionally kept — no data migration needed for an existing installation.

### Task 13: Write basic smoke tests to verify the rename didn't break anything

**Files:**
- Create: `tests/Feature/Compliance/SmokeTest.php`

- [ ] **Step 1: Create the smoke test**

Create `tests/Feature/Compliance/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\FatooraSubmissionService;
use App\Domains\Compliance\Fatoora\Services\FatooraValidator;
use App\Domains\Compliance\Fatoora\Services\FatooraComplianceService;
use App\Domains\Compliance\FTA\Services\FtaService;
use App\Domains\Compliance\FTA\Services\FtaValidator;
use App\Domains\Compliance\FTA\Services\FtaXmlBuilder;
use Tests\TestCase;

/**
 * Smoke tests: verify renamed classes are resolvable from the container.
 * These tests catch namespace/autoload issues immediately.
 */
class SmokeTest extends TestCase
{
    public function test_fatoora_submission_service_resolves(): void
    {
        $service = $this->app->make(FatooraSubmissionService::class);
        $this->assertInstanceOf(FatooraSubmissionService::class, $service);
    }

    public function test_fatoora_validator_resolves(): void
    {
        $validator = $this->app->make(FatooraValidator::class);
        $this->assertInstanceOf(FatooraValidator::class, $validator);
    }

    public function test_fta_service_resolves(): void
    {
        $service = $this->app->make(FtaService::class);
        $this->assertInstanceOf(FtaService::class, $service);
    }

    public function test_fta_validator_resolves(): void
    {
        $validator = $this->app->make(FtaValidator::class);
        $this->assertInstanceOf(FtaValidator::class, $validator);
    }

    public function test_fta_xml_builder_uses_pint_ae_customization_id(): void
    {
        $builder = new \ReflectionClass(FtaXmlBuilder::class);
        $constant = $builder->getConstant('CUSTOMIZATION');
        $this->assertSame('urn:peppol:pint:billing-1@ae-1', $constant);
    }

    public function test_fta_xml_builder_uses_pint_ae_profile_id(): void
    {
        $builder = new \ReflectionClass(FtaXmlBuilder::class);
        $constant = $builder->getConstant('PROFILE_ID');
        $this->assertSame('urn:peppol:bis:billing', $constant);
    }

    public function test_compliance_sa_route_exists(): void
    {
        $this->assertNotNull(route('compliance.sa.submit', ['invoiceId' => 'test-id']));
    }

    public function test_compliance_ae_route_exists(): void
    {
        $this->assertNotNull(route('compliance.ae.submit', ['invoiceId' => 'test-id']));
    }

    public function test_fatoora_config_loads(): void
    {
        $env = config('fatoora.environment');
        $this->assertNotNull($env);
    }

    public function test_fta_config_loads(): void
    {
        $env = config('fta.environment');
        $this->assertNotNull($env);
    }
}
```

- [ ] **Step 2: Run the smoke tests**

```bash
cd C:/laragon/www/Masaar
php artisan test tests/Feature/Compliance/SmokeTest.php --verbose
```

Expected: 10 tests pass. If any fail, fix the namespace/route issue before proceeding.

- [ ] **Step 3: Run the full test suite to check for regressions**

```bash
php artisan test --stop-on-failure
```

Expected: all tests pass.

### Task 14: Final commit for Phase 1B+1C

- [ ] **Step 1: Check for any remaining Saudi/Zatca/UaeFta/UAE references outside their domains**

```bash
grep -rn "Compliance\\\\Saudi\|Compliance\\\\Zatca\|Compliance\\\\UAE\|UaeFta\|zatca:check\|zatca:process\|zatca:replay\|zatca:validate\|zatca:verify\|zatca:generate\|zatca:onboard\|zatca:sandbox" \
  app/ config/ routes/ tests/ --include="*.php" --include="*.php"
```

Expected: no output.

- [ ] **Step 2: Dump autoload**

```bash
composer dump-autoload
```

- [ ] **Step 3: Confirm app boots**

```bash
php artisan about
```

Expected: no class-not-found errors.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/Masaar
git add -A
git commit -m "refactor: rename Saudi→Fatoora, UAE→FTA; fix PINT AE spec; rename zatca: commands to fatoora:

- app/Domains/Compliance/Saudi/ → Compliance/Fatoora/
- app/Domains/Compliance/UAE/   → Compliance/FTA/
- ZatcaXxx classes → FatooraXxx; UaeFtaXxx classes → FtaXxx
- config/zatca.php → config/fatoora.php
- config/uae-fta.php → config/fta.php
- zatca: artisan commands → fatoora:
- FtaXmlBuilder: BIS 3.0 → Peppol PINT AE (urn:peppol:pint:billing-1@ae-1)
- Routes: /compliance/sa/ and /compliance/ae/ with deprecated aliases
- 10 smoke tests pass"
```

---

## Self-Review

### Spec coverage check
| Spec Section | Covered by Task |
|---|---|
| §3 Monorepo Structure (sdks/, docs/) | Task 1 |
| §7 Saudi→Fatoora rename | Tasks 2–3 |
| §7 Console commands fatoora: | Task 4 |
| §7 UAE→FTA rename | Tasks 9–10 |
| §8 UAE PINT AE correction | Task 11 |
| §6 /compliance/sa/ and /compliance/ae/ routes | Tasks 8, 10 |
| §6 Deprecated aliases | Tasks 8, 10 |
| §9 Tests | Task 13 |
| §10 Docs sa/ ae/ qa/ architecture/ | Task 1 |

**Gaps vs spec:** Organization model Saudi-specific methods (`getZatcaOnboardedAttribute`, `hasCompleteZatcaProfile`, `isValidVatNumber`) are NOT renamed in this plan — they are intentionally left for Phase 3 (data model) where the Organization model is refactored more deeply. Task 5 only fixes imports, not the method names.

### No placeholder check
✅ All steps have concrete commands or code.

### Type consistency check
✅ `FatooraSubmissionService` used in Task 3 matches Task 8 controller update.  
✅ `FtaService` used in Task 9 matches Task 10 controller update.  
✅ `FtaXmlBuilder::CUSTOMIZATION` verified in Task 13 test.

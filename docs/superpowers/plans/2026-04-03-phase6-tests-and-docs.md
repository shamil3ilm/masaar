# Phase 6: Tests + Docs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the spec's Phase 6 requirements — add missing test suites (FTA XML/validator/submission, multi-profile routing, FatooraEngine feature test) and fill out the per-jurisdiction documentation tree (SA, AE, architecture docs).

**Architecture:** Tests follow the existing Pest 3 style. Docs are Markdown files placed in the existing `docs/sa/`, `docs/ae/`, and `docs/architecture/` directories. No new production code is written in this phase — only tests and docs.

**Tech Stack:** Pest 3, Mockery, PHP 8.4, Markdown.

---

## File Map

| Action | Path | Purpose |
|--------|------|---------|
| Create | `tests/Feature/Compliance/FTA/FtaXmlBuilderTest.php` | Verify PINT AE spec constants in generated XML |
| Create | `tests/Feature/Compliance/FTA/FtaValidatorTest.php` | Validator rules: TRN format, currency, doc type, VAT rate |
| Create | `tests/Feature/Compliance/Router/MultiProfileRoutingTest.php` | One org with SA + AE profiles routes correctly |
| Create | `tests/Feature/Organization/MultiJurisdictionTest.php` | End-to-end: org with two profiles, router picks correct engine |
| Create | `docs/sa/COMPLIANCE-RULES.md` | KSA business rules reference (BR-KSA-*) |
| Create | `docs/sa/INTEGRATION-GUIDE.md` | How to call the platform API for KSA |
| Create | `docs/ae/COMPLIANCE-RULES.md` | UAE FTA business rules, B2B/B2G scope |
| Create | `docs/ae/INTEGRATION-GUIDE.md` | How to call the platform API for UAE |
| Create | `docs/architecture/JURISDICTION-ROUTING.md` | How ComplianceRouter works, how to add a jurisdiction |
| Create | `docs/architecture/ORGANIZATION-MODEL.md` | Group → Org → Profile hierarchy |

---

### Task 1: FTA XML builder tests — verify PINT AE spec

**Files:**
- Create: `tests/Feature/Compliance/FTA/FtaXmlBuilderTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Compliance/FTA/FtaXmlBuilderTest.php

declare(strict_types=1);

use App\Domains\Compliance\FTA\DTOs\FtaInvoiceData;
use App\Domains\Compliance\FTA\Services\FtaXmlBuilder;

function makeFtaInvoiceData(array $overrides = []): FtaInvoiceData
{
    return new FtaInvoiceData(
        invoiceNumber: $overrides['invoiceNumber'] ?? 'INV-AE-001',
        invoiceDate: $overrides['invoiceDate'] ?? '2026-04-01',
        dueDate: $overrides['dueDate'] ?? '2026-04-30',
        currencyCode: $overrides['currencyCode'] ?? 'AED',
        supplierName: $overrides['supplierName'] ?? 'Acme UAE LLC',
        supplierTrn: $overrides['supplierTrn'] ?? '100000000000003',
        supplierStreet: $overrides['supplierStreet'] ?? '1 Sheikh Zayed Road',
        supplierCity: $overrides['supplierCity'] ?? 'Dubai',
        supplierCountry: $overrides['supplierCountry'] ?? 'AE',
        customerName: $overrides['customerName'] ?? 'Buyer Corp',
        customerTrn: $overrides['customerTrn'] ?? '200000000000009',
        customerStreet: $overrides['customerStreet'] ?? '5 Business Bay',
        customerCity: $overrides['customerCity'] ?? 'Dubai',
        customerCountry: $overrides['customerCountry'] ?? 'AE',
        lineExtensionAmount: $overrides['lineExtensionAmount'] ?? 1000.00,
        taxExclusiveAmount: $overrides['taxExclusiveAmount'] ?? 1000.00,
        taxInclusiveAmount: $overrides['taxInclusiveAmount'] ?? 1050.00,
        payableAmount: $overrides['payableAmount'] ?? 1050.00,
        vatAmount: $overrides['vatAmount'] ?? 50.00,
        vatRate: $overrides['vatRate'] ?? 0.05,
        lines: $overrides['lines'] ?? [
            ['description' => 'Consulting', 'quantity' => 1.0, 'unitPrice' => 1000.00, 'lineTotal' => 1000.00, 'vatRate' => 0.05],
        ],
        documentType: $overrides['documentType'] ?? '380',
        creditNoteReference: $overrides['creditNoteReference'] ?? null,
    );
}

it('emits the correct PINT AE CustomizationID', function () {
    $builder = app(FtaXmlBuilder::class);
    $xml = $builder->build(makeFtaInvoiceData());

    expect($xml)->toContain('urn:peppol:pint:billing-1@ae-1');
});

it('emits the correct Peppol ProfileID', function () {
    $builder = app(FtaXmlBuilder::class);
    $xml = $builder->build(makeFtaInvoiceData());

    expect($xml)->toContain('urn:peppol:bis:billing');
});

it('does NOT emit the old BIS Billing 3.0 customization ID', function () {
    $builder = app(FtaXmlBuilder::class);
    $xml = $builder->build(makeFtaInvoiceData());

    expect($xml)->not->toContain('urn:cen.eu:en16931:2017');
    expect($xml)->not->toContain('urn:fdc:peppol.eu:2017:poacc:billing:3.0');
});

it('includes the invoice number in the XML', function () {
    $builder = app(FtaXmlBuilder::class);
    $xml = $builder->build(makeFtaInvoiceData(['invoiceNumber' => 'INV-UAE-9999']));

    expect($xml)->toContain('INV-UAE-9999');
});

it('includes VAT amount in the XML', function () {
    $builder = app(FtaXmlBuilder::class);
    $xml = $builder->build(makeFtaInvoiceData());

    expect($xml)->toContain('50'); // VAT amount
});
```

- [ ] **Step 2: Run the test**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Feature/Compliance/FTA/FtaXmlBuilderTest.php -v
```

Expected: 5 PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Compliance/FTA/FtaXmlBuilderTest.php
git commit -m "test: add FtaXmlBuilder PINT AE spec verification tests"
```

---

### Task 2: FTA validator tests

**Files:**
- Create: `tests/Feature/Compliance/FTA/FtaValidatorTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Compliance/FTA/FtaValidatorTest.php

declare(strict_types=1);

use App\Domains\Compliance\FTA\Exceptions\FtaException;
use App\Domains\Compliance\FTA\Services\FtaValidator;

// Re-use makeFtaInvoiceData() helper from FtaXmlBuilderTest — already in scope via Pest global

it('passes validation for a valid invoice', function () {
    $validator = app(FtaValidator::class);

    expect(fn () => $validator->validate(makeFtaInvoiceData()))->not->toThrow(\Throwable::class);
});

it('throws for non-AED currency', function () {
    $validator = app(FtaValidator::class);

    expect(fn () => $validator->validate(makeFtaInvoiceData(['currencyCode' => 'SAR'])))
        ->toThrow(FtaException::class, 'Currency');
});

it('throws for invalid TRN (too short)', function () {
    $validator = app(FtaValidator::class);

    expect(fn () => $validator->validate(makeFtaInvoiceData(['supplierTrn' => '12345'])))
        ->toThrow(FtaException::class);
});

it('throws for invalid TRN (non-numeric)', function () {
    $validator = app(FtaValidator::class);

    expect(fn () => $validator->validate(makeFtaInvoiceData(['supplierTrn' => 'ABC000000000003'])))
        ->toThrow(FtaException::class);
});

it('throws for invalid document type', function () {
    $validator = app(FtaValidator::class);

    expect(fn () => $validator->validate(makeFtaInvoiceData(['documentType' => '999'])))
        ->toThrow(FtaException::class, 'Document type');
});

it('throws for invalid VAT rate', function () {
    $validator = app(FtaValidator::class);

    expect(fn () => $validator->validate(makeFtaInvoiceData(['vatRate' => 0.15])))
        ->toThrow(FtaException::class, 'VAT rate');
});

it('throws for credit note missing reference', function () {
    $validator = app(FtaValidator::class);

    expect(fn () => $validator->validate(makeFtaInvoiceData([
        'documentType'        => '381',
        'creditNoteReference' => null,
    ])))->toThrow(FtaException::class, 'reference');
});

it('accepts zero VAT rate', function () {
    $validator = app(FtaValidator::class);

    $data = makeFtaInvoiceData([
        'vatRate'             => 0.00,
        'vatAmount'           => 0.00,
        'taxInclusiveAmount'  => 1000.00,
    ]);

    expect(fn () => $validator->validate($data))->not->toThrow(\Throwable::class);
});
```

**Note:** `makeFtaInvoiceData()` is defined in `FtaXmlBuilderTest.php` in the same directory — in Pest, top-level functions defined in one test file are **not** automatically available in another. Define `makeFtaInvoiceData()` again in this file (or extract it to `tests/Helpers/FtaHelpers.php` and include it). The simplest approach: redefine it locally in this file (DRY violation acceptable for test helpers this small).

- [ ] **Step 2: Run the test**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Feature/Compliance/FTA/FtaValidatorTest.php -v
```

Expected: 8 PASS.

- [ ] **Step 3: Run both FTA tests together**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Feature/Compliance/FTA/ -v
```

Expected: 13 PASS (5 XML + 8 validator).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Compliance/FTA/FtaValidatorTest.php
git commit -m "test: add FtaValidator UAE business rule tests"
```

---

### Task 3: Multi-profile routing test

**Files:**
- Create: `tests/Feature/Compliance/Router/MultiProfileRoutingTest.php`

This test verifies that one organization with both SA and AE compliance profiles routes correctly to the right engine for each profile.

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Compliance/Router/MultiProfileRoutingTest.php

declare(strict_types=1);

use App\Domains\Compliance\ComplianceRouter;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\FTA\FtaEngine;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('routes correctly for an org with both SA and AE profiles', function () {
    $org = Organization::create([
        'name'    => 'Multinational Corp',
        'country' => 'SA',
        'status'  => 'active',
    ]);

    $saProfile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => ['vat_number' => '300000000000003'],
    ]);

    $aeProfile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'AE',
        'engine'          => 'fta',
        'status'          => 'active',
        'settings'        => ['vat_number' => '100000000000003'],
    ]);

    $router = app(ComplianceRouter::class);

    expect($router->engineFor($saProfile))->toBeInstanceOf(FatooraEngine::class)
        ->and($router->engineFor($aeProfile))->toBeInstanceOf(FtaEngine::class);
});

it('complianceProfileFor returns correct profile per jurisdiction', function () {
    $org = Organization::create([
        'name'    => 'Dual Corp',
        'country' => 'SA',
        'status'  => 'active',
    ]);

    ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => [],
    ]);

    ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'AE',
        'engine'          => 'fta',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $saProfile = $org->complianceProfileFor('SA');
    $aeProfile = $org->complianceProfileFor('AE');
    $qaProfile = $org->complianceProfileFor('QA');

    expect($saProfile)->not->toBeNull()
        ->and($saProfile->engine)->toBe('fatoora')
        ->and($aeProfile)->not->toBeNull()
        ->and($aeProfile->engine)->toBe('fta')
        ->and($qaProfile)->toBeNull();
});

it('suspended profile is not returned by complianceProfileFor', function () {
    $org = Organization::create([
        'name'    => 'Suspended Corp',
        'country' => 'SA',
        'status'  => 'active',
    ]);

    ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'suspended', // not active
        'settings'        => [],
    ]);

    expect($org->complianceProfileFor('SA'))->toBeNull();
});
```

- [ ] **Step 2: Run the test**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Feature/Compliance/Router/MultiProfileRoutingTest.php -v
```

Expected: 3 PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Compliance/Router/MultiProfileRoutingTest.php
git commit -m "test: add multi-profile routing tests for org with SA and AE profiles"
```

---

### Task 4: Multi-jurisdiction end-to-end test

**Files:**
- Create: `tests/Feature/Organization/MultiJurisdictionTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Organization/MultiJurisdictionTest.php

declare(strict_types=1);

use App\Domains\Compliance\ComplianceRouter;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\FTA\FtaEngine;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('an org in a group with two profiles submits to the right engine per profile', function () {
    // Holding group
    $group = OrganizationGroup::create([
        'name'   => 'GCC Holdings',
        'status' => 'active',
    ]);

    // One org, two jurisdictions
    $org = Organization::create([
        'name'     => 'GCC Operating Co',
        'country'  => 'SA',
        'status'   => 'active',
        'group_id' => $group->id,
    ]);

    $saProfile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $aeProfile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'AE',
        'engine'          => 'fta',
        'status'          => 'active',
        'settings'        => [],
    ]);

    // Verify group → org relationship
    expect($org->group->id)->toBe($group->id)
        ->and($group->organizations()->count())->toBe(1);

    // Verify router returns the correct engine per profile
    $router = app(ComplianceRouter::class);

    expect($router->engineFor($saProfile))->toBeInstanceOf(FatooraEngine::class)
        ->and($router->engineFor($aeProfile))->toBeInstanceOf(FtaEngine::class);
});

it('invoice can be linked to a compliance profile', function () {
    $org = Organization::create([
        'name' => 'Invoice Linker Corp', 'country' => 'SA', 'status' => 'active',
    ]);

    $profile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $invoice = Invoice::create([
        'organization_id'       => $org->id,
        'compliance_profile_id' => $profile->id,
        'invoice_number'        => 'INV-MJ-001',
        'type'                  => 'standard',
        'status'                => 'draft',
        'issue_date'            => now()->toDateString(),
        'currency'              => 'SAR',
        'buyer_name'            => 'Buyer Co',
    ]);

    expect($invoice->complianceProfile->jurisdiction)->toBe('SA')
        ->and($invoice->complianceProfile->engine)->toBe('fatoora');
});

it('mock submit routes correctly per jurisdiction and records result', function () {
    $org = Organization::create([
        'name' => 'Mock Submit Corp', 'country' => 'AE', 'status' => 'active',
    ]);

    $aeProfile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'AE',
        'engine'          => 'fta',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $invoice = Invoice::create([
        'organization_id' => $org->id,
        'invoice_number'  => 'INV-AE-001',
        'type'            => 'standard',
        'status'          => 'draft',
        'issue_date'      => now()->toDateString(),
        'currency'        => 'SAR',
        'buyer_name'      => 'UAE Buyer',
    ]);

    $mockEngine = Mockery::mock(FtaEngine::class);
    $mockEngine->allows('supports')->with('AE')->andReturns(true);
    $mockEngine->expects('submit')->once()->andReturns(
        new SubmissionResult(true, 'fta-sub-001', 'peppol-ref-001', 'queued', [], null)
    );

    $router = new ComplianceRouter([$mockEngine]);
    $result = $router->submit($invoice, $aeProfile);

    expect($result->success)->toBeTrue()
        ->and($result->submissionId)->toBe('fta-sub-001')
        ->and($result->status)->toBe('queued');
});
```

- [ ] **Step 2: Run the test**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Feature/Organization/MultiJurisdictionTest.php -v
```

Expected: 3 PASS.

- [ ] **Step 3: Run full suite**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test
```

Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Organization/MultiJurisdictionTest.php
git commit -m "test: add multi-jurisdiction end-to-end tests (group, profiles, routing)"
```

---

### Task 5: Per-jurisdiction documentation

**Files:**
- Create: `docs/sa/COMPLIANCE-RULES.md`
- Create: `docs/sa/INTEGRATION-GUIDE.md`
- Create: `docs/ae/COMPLIANCE-RULES.md`
- Create: `docs/ae/INTEGRATION-GUIDE.md`

- [ ] **Step 1: Create `docs/sa/COMPLIANCE-RULES.md`**

```markdown
# Saudi Arabia — Fatoora Compliance Rules Reference

**Authority:** ZATCA  
**Regulation:** E-Invoicing Regulation, Article 3  
**Last updated:** 2026-04-03

---

## Invoice Types

| Code | Type | Flow | Notes |
|------|------|------|-------|
| `standard` | B2B / Standard Tax Invoice | **Clearance** — ZATCA stamps before delivery to buyer | Required for transactions ≥ SAR 1,000 with VAT-registered buyers |
| `simplified` | B2C / Simplified Tax Invoice | **Reporting** — submit within 24 hours of issuance | For consumer sales |
| `credit_note` | Credit Note | Clearance | Must reference original invoice UUID |
| `debit_note` | Debit Note | Clearance | Must reference original invoice UUID |

## Business Rules (BR-KSA)

| Rule ID | Rule | Engine Behaviour |
|---------|------|-----------------|
| BR-KSA-01 | VAT number must be 15 digits, start and end with `3` | `Organization.isValidVatNumber()` rejects others |
| BR-KSA-02 | Invoice UUID (BT-124) must be unique per organization | `InvoiceHasher` generates; duplicates rejected by ZATCA |
| BR-KSA-03 | ICV (Invoice Counter Value) must be sequential, no gaps | `HashChain` service tracks ICV per branch |
| BR-KSA-04 | Previous invoice hash (PIH) must be included in XML | Linked to prior invoice hash; first invoice uses fixed seed |
| BR-KSA-05 | Standard invoices require clearance before delivery | `FatooraSubmissionService` enforces `submit → CLEARED` before status changes to `issued` |
| BR-KSA-06 | QR code (Phase 2) must encode 9 TLV tags | `QrCodeGenerator` enforces 9-tag structure |
| BR-KSA-07 | Supply date must be present for deferred supply | `issue_date` vs `supply_date` both stored on `Invoice` |
| BR-KSA-08 | Credit/debit notes must have billing reference | Validated in `FatooraComplianceService` |

## Rollout Waves

| Wave | Revenue Threshold | Deadline |
|------|-------------------|---------|
| 1 | ≥ SAR 3 billion | 2023-01-01 |
| … | … | … |
| 24 | ≥ SAR 375,000 | **2026-06-30** |

Wave is tracked in `ComplianceProfile.settings['wave']` for reporting purposes.

## Environments

| Environment | Base URL | Purpose |
|-------------|----------|---------|
| Sandbox | `https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal` | Development + testing |
| Simulation | `https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation` | Pre-production compliance check |
| Production | `https://gw-fatoora.zatca.gov.sa/e-invoicing/core` | Live submissions |

Set via `FATOORA_ENV=sandbox|simulation|production` in `.env`.

## Known Limitations

- Clearance is synchronous — the API blocks until ZATCA stamps or rejects the invoice.
- CSID certificates expire. Use `fatoora:renew-certificate` before expiry. See `docs/architecture/ADDING-A-JURISDICTION.md` for the renewal flow.
- Offline mode queues submissions locally when ZATCA is unreachable. Queued invoices are processed by the `ProcessFatooraSubmission` job.
```

- [ ] **Step 2: Create `docs/sa/INTEGRATION-GUIDE.md`**

```markdown
# Saudi Arabia — Platform API Integration Guide

**Base URL:** `https://your-masaar-instance.com/api`  
**Auth:** JWT Bearer token (`Authorization: Bearer <token>`)

---

## Quick Start

### 1. Register and log in

```http
POST /api/auth/register
{
  "name": "My Company",
  "email": "admin@mycompany.sa",
  "password": "secret"
}

POST /api/auth/login
→ returns { "token": "eyJ..." }
```

### 2. Create a Compliance Profile (SA)

```http
POST /api/organizations/{org_id}/compliance-profiles
Authorization: Bearer <token>
{
  "jurisdiction": "SA",
  "engine": "fatoora",
  "status": "pending_onboarding",
  "settings": {
    "vat_number": "300000000000003",
    "wave": 24
  }
}
→ returns { "data": { "id": "...", "jurisdiction": "SA", ... } }
```

### 3. Onboard (CSID)

```http
POST /api/compliance/onboarding/request-csid
{ "otp": "123456" }
```

### 4. Generate + Submit an Invoice

```http
POST /api/compliance/sa/generate/{invoice_id}
→ returns { "data": { "hash": "...", "qr_code": "..." } }

POST /api/compliance/sa/submit/{invoice_id}
→ returns { "data": { "clearance_status": "CLEARED", ... } }
```

### 5. Check Status

```http
GET /api/compliance/sa/status/{submission_id}
```

---

## Error Codes

| Code | Meaning |
|------|---------|
| `FATOORA_REJECTED` | ZATCA rejected the invoice — check `errors` array |
| `FATOORA_UNAVAILABLE` | ZATCA API unreachable — invoice queued for offline retry |
| `CERT_EXPIRED` | CSID certificate expired — run `fatoora:renew-certificate` |
| `INVALID_VAT` | VAT number format invalid (must be 15 digits, start+end with 3) |

## Deprecated Endpoints

The following endpoints are kept for v1 backward compatibility and will be removed in v2.0:

```
POST /api/compliance/zatca/submit/{id}  →  Use /api/compliance/sa/submit/{id}
GET  /api/compliance/zatca/status/{id}  →  Use /api/compliance/sa/status/{id}
```
```

- [ ] **Step 3: Create `docs/ae/COMPLIANCE-RULES.md`**

```markdown
# UAE — FTA e-Invoicing (Peppol PINT AE) Compliance Rules Reference

**Authority:** UAE Federal Tax Authority (FTA)  
**Regulation:** Ministerial Decision No. 243 & 244 of 2025  
**XML Spec:** Peppol PINT AE (`urn:peppol:pint:billing-1@ae-1`)  
**Last updated:** 2026-04-03

---

## Scope

Applies to any person **conducting business in the UAE** for **B2B and B2G** transactions.  
**B2C is excluded** from mandatory e-invoicing until further regulatory notice.

| Scenario | In Scope |
|----------|----------|
| UAE-established company → UAE-established buyer | Yes |
| UAE-established company → UAE government body | Yes |
| UAE-established company → consumer | No (B2C) |
| Non-UAE company trading internationally | Check residency |

## XML Specification

| Field | Value |
|-------|-------|
| `CustomizationID` | `urn:peppol:pint:billing-1@ae-1` |
| `ProfileID` | `urn:peppol:bis:billing` |
| Participant ID scheme | `0235` + first 10 digits of TRN |
| XML namespace | UBL 2.1 |

**Do NOT use BIS Billing 3.0** (`urn:cen.eu:en16931:2017`) — that is the European profile.

## Business Rules (BR-AE)

| Rule ID | Rule | Engine Behaviour |
|---------|------|-----------------|
| BR-AE-01 | TRN must be exactly 15 digits | `FtaValidator.validateTrn()` rejects others |
| BR-AE-02 | Currency must be AED | `FtaValidator` throws if non-AED |
| BR-AE-03 | Document type must be 380 (invoice), 381 (credit note), or 383 (debit note) | `FtaValidator` rejects unknown types |
| BR-AE-04 | VAT rate must be 5% or 0% (zero-rated) | `FtaValidator` rejects other rates |
| BR-AE-05 | Credit/debit notes must reference original invoice | `creditNoteReference` required for types 381/383 |
| BR-AE-06 | Tax-inclusive total = tax-exclusive + VAT, within ±0.01 rounding | `FtaValidator.validateAmounts()` |
| BR-AE-07 | B2C invoices must not be submitted | Routing guard in `FtaService.submit()` |

## Rollout Timeline

| Phase | Threshold | Mandatory From |
|-------|-----------|---------------|
| Voluntary Pilot | All | 2026-07-01 |
| Phase 1 | Revenue ≥ AED 50M | **2027-01-01** |
| Phase 2 | Revenue < AED 50M | 2027-07-01 |
| Phase 3 | Federal government entities | 2027-10-01 |

## Environments

| Environment | Notes |
|-------------|-------|
| Sandbox | Available to registered participants via FTA developer portal |
| Production | UAE FTA Access Point API endpoint |

Set via `FTA_ENV=sandbox|production` in `.env`.

## Known Limitations

- FTA submissions are **asynchronous** — the API returns a submission reference; status must be polled via `checkStatus`.
- Peppol participant ID registration (`0235` + TRN prefix) must be completed with a UAE FTA Access Point Service Provider (ASP) before production submission.
- B2C invoices are excluded — the `FtaService` skips submission for `simplified` invoice types.
```

- [ ] **Step 4: Create `docs/ae/INTEGRATION-GUIDE.md`**

```markdown
# UAE — Platform API Integration Guide

**Base URL:** `https://your-masaar-instance.com/api`  
**Auth:** JWT Bearer token (`Authorization: Bearer <token>`)

---

## Quick Start

### 1. Create a Compliance Profile (AE)

```http
POST /api/organizations/{org_id}/compliance-profiles
Authorization: Bearer <token>
{
  "jurisdiction": "AE",
  "engine": "fta",
  "status": "pending_onboarding",
  "settings": {
    "vat_number": "100000000000003",
    "peppol_participant_id": "0235100000000"
  }
}
→ returns { "data": { "id": "...", "jurisdiction": "AE", ... } }
```

### 2. Submit an Invoice

```http
POST /api/compliance/ae/submit/{invoice_id}
Authorization: Bearer <token>
→ returns {
    "data": {
      "submission_id": "...",
      "status": "queued",
      "fta_ref": null
    }
  }
```

### 3. Check Status (async)

```http
GET /api/compliance/ae/status/{submission_id}
→ returns {
    "data": {
      "status": "accepted",
      "fta_validation_status": "PASS",
      "accepted_at": "2027-01-15T10:30:00Z"
    }
  }
```

### 4. Retry a Failed Submission

```http
POST /api/compliance/ae/retry/{submission_id}
```

---

## Invoice Requirements

UAE FTA invoices **must** include:
- Supplier TRN (15 digits)
- Customer TRN (for B2B — leave null for B2C, which is excluded)
- Currency: AED
- Document type: 380 (invoice), 381 (credit note), 383 (debit note)
- VAT rate: 5% or 0% (zero-rated)

## Error Codes

| Code | Meaning |
|------|---------|
| `FTA_REJECTED` | FTA rejected the invoice — check `errors` array |
| `FTA_INVALID_TRN` | TRN must be exactly 15 digits |
| `FTA_INVALID_CURRENCY` | Only AED is accepted |
| `FTA_B2C_EXCLUDED` | B2C invoices are not submitted to FTA |
| `FTA_MISSING_REFERENCE` | Credit/debit notes must reference the original invoice |

## Deprecated Endpoints

```
POST /api/compliance/uae-fta/submit/{id}  →  Use /api/compliance/ae/submit/{id}
GET  /api/compliance/uae-fta/status/{id}  →  Use /api/compliance/ae/status/{id}
```
```

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/Masaar
git add docs/sa/COMPLIANCE-RULES.md \
        docs/sa/INTEGRATION-GUIDE.md \
        docs/ae/COMPLIANCE-RULES.md \
        docs/ae/INTEGRATION-GUIDE.md
git commit -m "docs: add SA and AE compliance rules and integration guides"
```

---

### Task 6: Architecture documentation

**Files:**
- Create: `docs/architecture/JURISDICTION-ROUTING.md`
- Create: `docs/architecture/ORGANIZATION-MODEL.md`

- [ ] **Step 1: Create `docs/architecture/JURISDICTION-ROUTING.md`**

```markdown
# Jurisdiction Routing — How It Works

**File:** `app/Domains/Compliance/ComplianceRouter.php`  
**Provider:** `app/Providers/ComplianceServiceProvider.php`

---

## The Problem

An invoice must be submitted to exactly one jurisdiction's authority (ZATCA for SA, FTA for AE, GTA for QA). The routing decision must be determined by the **supplier entity's country of establishment**, not the buyer's country or the place of supply.

## The Solution

`ComplianceRouter` resolves the correct `ComplianceEngine` implementation by:
1. Accepting a `ComplianceProfile` (which carries `jurisdiction`, e.g. `'SA'`)
2. Iterating all registered engines and calling `$engine->supports($jurisdiction)`
3. Delegating to the first matching engine
4. Throwing `UnsupportedJurisdictionException` if none match

```php
// Example
$profile = $org->complianceProfileFor('SA'); // or 'AE'
$engine  = $router->engineFor($profile);    // FatooraEngine or FtaEngine
$result  = $router->submit($invoice, $profile);
```

## Engine Registration

Engines are registered in `ComplianceServiceProvider` using Laravel's IoC tagging:

```php
$this->app->tag([FatooraEngine::class, FtaEngine::class], 'compliance.engines');

$this->app->singleton(ComplianceRouter::class, function ($app) {
    return new ComplianceRouter(
        engines: iterator_to_array($app->tagged('compliance.engines')),
    );
});
```

## Adding a New Jurisdiction (e.g. Qatar GTA)

1. Create `app/Domains/Compliance/GTA/GtaEngine.php` implementing `ComplianceEngine`
2. Implement `supports()` to return `true` for `'QA'`
3. Implement `submit()`, `validate()`, `retry()`, `checkStatus()`
4. Add `GtaEngine::class` to the `tag()` call in `ComplianceServiceProvider`
5. Add `'QA' => 'gta'` to `BackfillComplianceProfilesSeeder::ENGINE_MAP`
6. Run `php artisan db:seed --class=BackfillComplianceProfilesSeeder`

Zero changes needed to `ComplianceRouter`, controllers, or any existing engine.

See `docs/architecture/ADDING-A-JURISDICTION.md` for the full step-by-step checklist.

## Routing Key

The routing key is `ComplianceProfile.jurisdiction` (ISO 3166-1 alpha-2). This is set when the profile is created and never changes. A single organization can have multiple profiles — one per jurisdiction.

## What a ComplianceEngine Must Do

```php
interface ComplianceEngine
{
    public function supports(string $jurisdiction): bool;
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult;
    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult;
    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult;
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult;
}
```

`SubmissionResult` and `ValidationResult` are `final readonly` DTOs in `app/Domains/Compliance/Contracts/`.
```

- [ ] **Step 2: Create `docs/architecture/ORGANIZATION-MODEL.md`**

```markdown
# Organization Model — Group → Org → Profile Hierarchy

**Models:**
- `app/Domains/Organization/Models/OrganizationGroup.php`
- `app/Domains/Organization/Models/Organization.php`
- `app/Domains/Organization/Models/ComplianceProfile.php`

---

## The Hierarchy

```
OrganizationGroup  (optional, for holding companies / franchise groups)
  └── Organization  (legal entity / tenant)
        ├── ComplianceProfile (SA)  ← Fatoora engine
        ├── ComplianceProfile (AE)  ← FTA engine
        └── ComplianceProfile (QA)  ← GTA engine (future)
```

An organization is not required to belong to a group. Most single-entity customers will have exactly one organization and one compliance profile.

## ComplianceProfile Lifecycle

```
pending_onboarding → active → suspended → revoked
```

Only `active` profiles are returned by `Organization::complianceProfileFor($jurisdiction)`.

## Key Methods

```php
// On Organization:
$org->complianceProfiles()              // HasMany: all profiles
$org->complianceProfileFor('SA')        // ?ComplianceProfile: active SA profile
$org->group                             // ?OrganizationGroup: parent group

// On Organization (backward-compat):
$org->vat_number                        // reads from active SA profile, then JSON fallback
$org->zatca_onboarded                   // reads from active SA profile, then branch fallback

// On ComplianceProfile:
$profile->organization                  // BelongsTo: the org
$profile->invoices()                    // HasMany: invoices linked to this profile
$profile->isActive()                    // bool
$profile->setting('vat_number')         // mixed: reads from settings JSON
```

## Creating a Multi-Jurisdiction Organization

```php
$org = Organization::create([
    'name'    => 'GCC Operating Co',
    'country' => 'SA',
    'status'  => 'active',
]);

// SA profile
$org->complianceProfiles()->create([
    'jurisdiction' => 'SA',
    'engine'       => 'fatoora',
    'status'       => 'pending_onboarding',
    'settings'     => ['vat_number' => '300000000000003'],
]);

// AE profile
$org->complianceProfiles()->create([
    'jurisdiction' => 'AE',
    'engine'       => 'fta',
    'status'       => 'pending_onboarding',
    'settings'     => ['vat_number' => '100000000000003'],
]);
```

## Backfill

Existing organizations with a `compliance_profile` JSON blob can be migrated to the new `compliance_profiles` table:

```bash
php artisan db:seed --class=BackfillComplianceProfilesSeeder
```

This is idempotent — safe to run multiple times.

## Database Tables

| Table | Purpose |
|-------|---------|
| `organization_groups` | Optional parent groups |
| `organizations` | Legal entities / tenants (has `group_id` FK) |
| `compliance_profiles` | Per-jurisdiction compliance settings (unique on org+jurisdiction) |
| `invoices` | Has `compliance_profile_id` FK (set at submission, permanent audit record) |
```

- [ ] **Step 3: Commit**

```bash
cd C:/laragon/www/Masaar
git add docs/architecture/JURISDICTION-ROUTING.md \
        docs/architecture/ORGANIZATION-MODEL.md
git commit -m "docs: add architecture docs for jurisdiction routing and organization model"
```

---

### Task 7: Final test suite pass

- [ ] **Step 1: Run the complete test suite**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test -v
```

Expected: all green. Count the total — should be ≥ 95 tests.

- [ ] **Step 2: Check git log for Phase 6 commits**

```bash
cd C:/laragon/www/Masaar && git log --oneline -10
```

- [ ] **Step 3: No further commits needed if all green.**

---

## Self-Review

### Spec Coverage

| Spec requirement (Phase 6) | Task |
|---------------------------|------|
| `tests/Feature/Compliance/FTA/FtaXmlBuilderTest.php` — verify PINT AE | Task 1 |
| `tests/Feature/Compliance/FTA/FtaValidatorTest.php` | Task 2 |
| `tests/Feature/Compliance/Router/MultiProfileRoutingTest.php` | Task 3 |
| `tests/Feature/Organization/MultiJurisdictionTest.php` | Task 4 |
| `docs/sa/COMPLIANCE-RULES.md` | Task 5 |
| `docs/sa/INTEGRATION-GUIDE.md` | Task 5 |
| `docs/ae/COMPLIANCE-RULES.md` | Task 5 |
| `docs/ae/INTEGRATION-GUIDE.md` | Task 5 |
| `docs/architecture/JURISDICTION-ROUTING.md` | Task 6 |
| `docs/architecture/ORGANIZATION-MODEL.md` | Task 6 |

### Placeholder Check
No TBDs. Every code step shows complete content.

### Type Consistency
- `makeFtaInvoiceData()` helper in Task 1 and Task 2 both construct `FtaInvoiceData` with the same constructor signature.
- `ComplianceRouter` constructor and `engineFor()` used consistently across Tasks 3 and 4.

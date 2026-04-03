# Masaar Multi-Jurisdiction Compliance Platform — Design Spec

**Date:** 2026-04-02  
**Status:** Approved  
**Author:** Shamil  

---

## 1. Overview

Masaar is a GCC e-invoicing compliance API platform. This spec covers the evolution from a single-jurisdiction (KSA ZATCA) service to a multi-jurisdiction platform supporting Saudi Arabia (Fatoora/ZATCA), UAE (FTA Peppol PINT AE), and future GCC countries (Qatar GTA, etc.).

The goals are:
1. Unify KSA and UAE compliance under a single platform (`platform/`)
2. Establish a clean extensibility pattern so new jurisdictions are drop-in additions
3. Support multi-national organizations (one org, multiple compliance profiles) and holding groups (multiple orgs under a group)
4. Rename internal classes from ZATCA-branded to Fatoora/FTA-branded to avoid confusion
5. Restructure the project into a monorepo under `C:/laragon/www/Masaar/`

---

## 2. Regulatory Basis

### 2.1 Saudi Arabia — ZATCA Fatoora Phase 2

- **Authority:** ZATCA (Zakat, Tax and Customs Authority)
- **System name:** Fatoora (hence the internal naming)
- **Scope trigger:** Supplier **residency** in KSA (fixed establishment / registered place of business). Non-resident companies with a KSA VAT number but no KSA establishment are exempt.
- **Invoice types:** B2B (clearance) and B2C (reporting) — both mandatory for KSA-resident entities
- **XML standard:** UBL 2.1 with XAdES-BES digital signatures (ECDSA secp256k1)
- **QR code:** TLV-encoded, 9 tags (Phase 2)
- **Rollout:** Wave-by-wave by taxable revenue threshold. Wave 24 (SAR 375K threshold) deadline: 2026-06-30
- **Source:** ZATCA E-Invoicing Regulation Article 3; zatca.gov.sa

### 2.2 UAE — FTA e-Invoicing (Peppol PINT AE)

- **Authority:** UAE Federal Tax Authority (FTA)
- **Scope trigger:** Any person **conducting business in the UAE** for B2B/B2G transactions (B2C excluded until further notice)
- **XML standard:** Peppol PINT AE (UAE-specific national profile) — **NOT** BIS Billing 3.0
- **Participant ID format:** `0235` + first 10 digits of UAE TRN
- **Rollout:**
  - Voluntary pilot: 2026-07-01
  - Phase 1 mandatory (revenue ≥ AED 50M): 2027-01-01
  - Phase 2 mandatory (revenue < AED 50M): 2027-07-01
  - Phase 3 (Federal government): 2027-10-01
- **Source:** Ministerial Decision No. 243 & 244 of 2025; UAE Electronic Invoicing Guidelines v1.0 (Feb 2026)

### 2.3 Jurisdiction Routing Rule

The correct engine for an invoice is determined by the **supplier entity's country of establishment**, not the buyer's country and not the place of supply.

- Invoice issued by KSA-resident entity → Fatoora engine
- Invoice issued by UAE-established entity → FTA engine
- A single invoice cannot be dual-reported to both systems
- Routing key: `ComplianceProfile.jurisdiction` matched to the organization's registration for the supplier entity

---

## 3. Monorepo Structure

```
C:/laragon/www/Masaar/               ← Masaar brand root
├── platform/                        ← Compliance API (Laravel 12, PHP 8.4)
│   ├── app/
│   │   ├── Domains/
│   │   │   ├── Compliance/
│   │   │   │   ├── Contracts/
│   │   │   │   │   ├── ComplianceEngine.php
│   │   │   │   │   ├── Submission.php
│   │   │   │   │   └── ValidationResult.php
│   │   │   │   ├── ComplianceRouter.php
│   │   │   │   ├── Fatoora/         ← KSA ZATCA (renamed from Saudi/)
│   │   │   │   ├── FTA/             ← UAE FTA (renamed from UAE/, PINT AE corrected)
│   │   │   │   └── GTA/             ← Qatar (future, drop-in)
│   │   │   ├── Organization/
│   │   │   │   ├── Models/
│   │   │   │   │   ├── Organization.php        (refactored)
│   │   │   │   │   ├── OrganizationGroup.php   (new)
│   │   │   │   │   └── ComplianceProfile.php   (new)
│   │   │   │   └── Services/
│   │   │   ├── Invoice/
│   │   │   ├── Auth/
│   │   │   ├── Licensing/
│   │   │   ├── Logging/
│   │   │   └── Webhook/
│   │   └── Http/Controllers/Api/
│   │       ├── ComplianceController.php        (jurisdiction-aware)
│   │       ├── Fatoora/                        (KSA-specific endpoints)
│   │       └── FTA/                            (UAE-specific endpoints)
│   ├── routes/api.php
│   ├── tests/Feature/Compliance/
│   │   ├── Fatoora/
│   │   ├── FTA/
│   │   └── Router/
│   └── ...
├── erp/                             ← ERP backend (separate Laravel app)
├── sdks/                            ← Language SDKs (moved from platform/sdk/)
│   ├── php/
│   ├── typescript/
│   ├── python/
│   ├── java/
│   ├── go/
│   ├── kotlin/
│   ├── dart/
│   ├── swift/
│   ├── ruby/
│   ├── rust/
│   └── dotnet/
├── docs/
│   ├── sa/
│   │   ├── README.md
│   │   ├── COMPLIANCE-RULES.md
│   │   └── ONBOARDING-GUIDE.md
│   ├── ae/
│   │   ├── README.md
│   │   ├── COMPLIANCE-RULES.md
│   │   └── ONBOARDING-GUIDE.md
│   ├── architecture/
│   │   └── (this file + diagrams)
│   └── superpowers/specs/           ← design specs
└── README.md                        ← Masaar umbrella README
```

---

## 4. Data Model

### 4.1 New Tables

#### `organization_groups`
```sql
id             uuid PK
name           string
slug           string unique
status         enum(active, suspended)
metadata       json nullable
created_at, updated_at
```
Optional parent for holding companies and franchise groups. No organization is required to have one.

#### `compliance_profiles`
```sql
id                   uuid PK
organization_id      uuid FK → organizations
jurisdiction         char(2)   -- 'SA', 'AE', 'QA', 'IN', ...
engine               string    -- 'fatoora', 'fta', 'gta', ...
tax_registration_no  string    -- VAT (SA), TRN (AE), GSTIN (IN)
status               enum(pending_onboarding, active, suspended, revoked)
is_default           boolean   -- true when org has one profile
credentials          json      -- CSID certs (SA), Peppol participant ID (AE)
settings             json      -- jurisdiction config (wave, phase, env)
onboarded_at         timestamp nullable
expires_at           timestamp nullable
deleted_at           timestamp nullable  -- soft delete
created_at, updated_at
```

### 4.2 Changes to Existing Tables

#### `organizations` — additions
- `group_id` (uuid, nullable FK → `organization_groups`)
- Remove: `compliance_profile` JSON blob (deprecated; data migrated to `compliance_profiles`)
- Keep: `country`, address fields (legal entity record)

#### `invoices` — additions
- `compliance_profile_id` (uuid, nullable FK → `compliance_profiles`) — set at submission time, permanent audit record

#### `branches` — no structural change
Branch remains the ZATCA EGS unit concept within a KSA compliance profile. `country_code` default stays `'SA'` for existing branches; new branches inherit from their org's compliance profile.

### 4.3 Relationships

```
OrganizationGroup (optional)
  └── Organization  (hasMany)
        ├── ComplianceProfile  (hasMany)
        │     └── Submission  (hasMany, polymorphic by engine)
        └── Invoice  (hasMany)
              └── ComplianceProfile  (belongsTo, set at submission)
```

---

## 5. ComplianceEngine Contract

```php
interface ComplianceEngine
{
    /** Jurisdiction code this engine handles, e.g. 'SA', 'AE' */
    public function supports(string $jurisdiction): bool;

    /** Submit invoice to jurisdiction authority */
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionContract;

    /** Retry a failed/rejected submission */
    public function retry(SubmissionContract $submission): SubmissionContract;

    /** Poll authority for updated submission status */
    public function checkStatus(SubmissionContract $submission): SubmissionContract;

    /** Validate invoice against jurisdiction rules before submission */
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult;

    /** Run onboarding flow (CSID for SA, Peppol registration for AE) */
    public function onboard(ComplianceProfile $profile, array $credentials): void;
}
```

`ComplianceRouter` resolves the correct engine by:
1. Loading the org's `ComplianceProfile` matching `jurisdiction`
2. Resolving the registered engine from the IoC container (tagged collection)
3. Delegating the call

Adding Qatar = implement `ComplianceEngine`, register in `AppServiceProvider`. Zero changes to router, controller, or invoice domain.

---

## 6. API Routes

### New jurisdiction-scoped routes
```
POST   /api/compliance/sa/submit/{invoiceId}
POST   /api/compliance/ae/submit/{invoiceId}
POST   /api/compliance/{jurisdiction}/retry/{submissionId}
GET    /api/compliance/{jurisdiction}/status/{submissionId}
GET    /api/compliance/{jurisdiction}/submissions

POST   /api/compliance/sa/onboard             (Fatoora CSID flow)
POST   /api/compliance/sa/onboard/{branch}    (per-branch CSID)

GET    /api/organizations/{id}/compliance-profiles
POST   /api/organizations/{id}/compliance-profiles
DELETE /api/organizations/{id}/compliance-profiles/{profileId}

GET    /api/organization-groups
POST   /api/organization-groups
GET    /api/organization-groups/{id}
PATCH  /api/organization-groups/{id}
```

### Deprecated aliases (kept for v1 backward compatibility, removed in v2)
```
POST /api/compliance/zatca/*     → 301 to /api/compliance/sa/*
POST /api/compliance/uae-fta/*   → 301 to /api/compliance/ae/*
```

---

## 7. Class Renaming Map

### KSA domain: `Compliance/Saudi/` → `Compliance/Fatoora/`

| Old | New |
|-----|-----|
| `ZatcaClient` | `FatooraClient` |
| `ZatcaConfig` | `FatooraConfig` |
| `ZatcaException` | `FatooraException` |
| `ZatcaResponse` | `FatooraResponse` |
| `ZatcaSubmissionService` | `FatooraSubmissionService` |
| `ZatcaComplianceService` | `FatooraComplianceService` |
| `ZatcaConnectivityChecker` | `FatooraConnectivityChecker` |
| `ZatcaValidator` | `FatooraValidator` |
| `ZatcaSdkService` | `FatooraSdkService` |
| `ZatcaTime` (helper) | `FatooraTime` |
| `ZatcaGenerateCsr` (command) | `fatoora:generate-csr` |
| `ZatcaOnboarding` (command) | `fatoora:onboard` |
| `ZatcaSandboxTest` (command) | `fatoora:sandbox-test` |
| `ValidateZatcaCompliance` (command) | `fatoora:validate` |
| `ProcessZatcaSubmission` (job) | `ProcessFatooraSubmission` |

### UAE domain: `Compliance/UAE/` → `Compliance/FTA/`

| Old | New |
|-----|-----|
| `UaeFtaService` | `FtaService` |
| `UaeFtaXmlBuilder` | `FtaXmlBuilder` (corrected to Peppol PINT AE) |
| `UaeFtaValidator` | `FtaValidator` |
| `UaeFtaSubmission` | `FtaSubmission` |
| `UaeFtaInvoiceData` | `FtaInvoiceData` |
| `UaeFtaResponse` | `FtaResponse` |
| `UaeFtaStatus` | `FtaStatus` |
| `UaeFtaException` | `FtaException` |
| Route prefix `/compliance/uae-fta/` | `/compliance/ae/` |

### PHP namespace root: unchanged (`App\`)
The top-level Laravel namespace stays `App\` — the cost of renaming every file outweighs the benefit. The brand is expressed through directory names and class names, not the autoload namespace.

---

## 8. UAE FTA Spec Correction

The current `UaeFtaXmlBuilder` uses **Peppol BIS Billing 3.0** (European profile). This is wrong.

UAE uses **Peppol PINT AE** — the UAE national Peppol profile:

| Field | Current (wrong) | Correct |
|-------|----------------|---------|
| `CustomizationID` | `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0` | `urn:peppol:pint:billing-1@ae-1` |
| `ProfileID` | `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` | `urn:peppol:bis:billing` |
| Participant ID scheme | n/a | `0235` + first 10 digits of TRN |
| Submission endpoint | Generic Peppol | UAE FTA Access Point API |
| B2C invoices | Submitted | Excluded (B2B/B2G only) |

The `FtaXmlBuilder` (renamed) must be corrected to emit PINT AE-compliant XML.

---

## 9. Testing Strategy

```
tests/Feature/Compliance/
├── Router/
│   ├── JurisdictionRoutingTest.php    (SA org → Fatoora, AE org → FTA, multi-profile)
│   └── MultiProfileRoutingTest.php   (org with SA + AE profiles)
├── Fatoora/
│   ├── (existing KSA tests migrated and renamed)
│   └── FatooraSubmissionTest.php
└── FTA/
    ├── FtaSubmissionTest.php
    ├── FtaXmlBuilderTest.php          (verify PINT AE spec)
    └── FtaValidatorTest.php

tests/Feature/Organization/
├── ComplianceProfileTest.php         (CRUD, jurisdiction validation)
├── OrganizationGroupTest.php         (group → org hierarchy)
└── MultiJurisdictionTest.php         (one org, two profiles, correct routing)
```

Shared test traits:
- `HasComplianceProfile` — creates profile + org for any jurisdiction
- `HasOrganizationGroup` — creates group with child orgs
- `HasFatooraProfile` — KSA-specific fixture (VAT number, branch, CSID mock)
- `HasFtaProfile` — UAE-specific fixture (TRN, Peppol participant ID mock)

---

## 10. Documentation Structure

Each platform (Fatoora/FTA/future) gets its own self-contained documentation tree. Docs live both inside `platform/` (developer-facing) and in the top-level `docs/` (business/integration-facing).

```
Masaar/
├── docs/                              ← top-level: business + integration docs
│   ├── sa/                            ← KSA Fatoora
│   │   ├── README.md                  (overview, regulatory context, rollout waves)
│   │   ├── COMPLIANCE-RULES.md        (BR-KSA-* business rules reference)
│   │   ├── ONBOARDING-GUIDE.md        (CCSID → PCSID flow, wave eligibility)
│   │   ├── INTEGRATION-GUIDE.md       (how to call the platform API for KSA)
│   │   └── CHANGELOG.md               (KSA-specific spec changes over time)
│   ├── ae/                            ← UAE FTA
│   │   ├── README.md                  (overview, PINT AE spec, mandate timeline)
│   │   ├── COMPLIANCE-RULES.md        (UAE FTA business rules, B2B/B2G scope)
│   │   ├── ONBOARDING-GUIDE.md        (Peppol participant registration, ASP setup)
│   │   ├── INTEGRATION-GUIDE.md       (how to call the platform API for UAE)
│   │   └── CHANGELOG.md               (UAE spec/regulation changes)
│   ├── qa/                            ← Qatar GTA (placeholder, populated when implemented)
│   │   └── README.md
│   ├── architecture/
│   │   ├── JURISDICTION-ROUTING.md    (how ComplianceRouter works)
│   │   ├── ORGANIZATION-MODEL.md      (Group → Org → Profile hierarchy)
│   │   └── ADDING-A-JURISDICTION.md   (step-by-step: how to add Qatar/others)
│   └── superpowers/specs/
│       └── 2026-04-02-masaar-multi-jurisdiction-design.md
│
└── platform/docs/                     ← developer-facing: internal platform docs
    ├── fatoora/
    │   ├── README.md                  (Fatoora engine internals, class map)
    │   ├── CERTIFICATE-LIFECYCLE.md   (CSID rotation, expiry, revocation)
    │   ├── OFFLINE-QUEUE.md           (offline mode, circuit breaker)
    │   ├── HASH-CHAIN.md              (ICV counter, hash chain rules)
    │   └── TESTING.md                 (how to run KSA tests, sandbox setup)
    ├── fta/
    │   ├── README.md                  (FTA engine internals, Peppol PINT AE spec)
    │   ├── PEPPOL-PINT-AE.md          (customization ID, participant ID, fields)
    │   ├── SUBMISSION-FLOW.md         (submit → accepted/pending_review lifecycle)
    │   └── TESTING.md                 (how to run UAE tests, FTA sandbox)
    ├── organization-model/
    │   └── README.md                  (ComplianceProfile setup, multi-profile routing)
    └── sdk/
        └── README.md                  (how to update each SDK after API changes)
```

Each jurisdiction README (both top-level and platform) includes:
- Regulatory authority and official source links
- Scope trigger (who must comply)
- XML standard and version
- Mandatory rollout timeline
- Environment URLs (sandbox, simulation, production)
- Known limitations and edge cases

---

## 11. Migration Strategy

### Phase 1 — Monorepo restructure (no code changes)
1. Create `platform/`, `erp/`, `sdks/`, `docs/` folders under `Masaar/`
2. Move current Masaar app contents → `platform/`
3. Move `platform/sdk/` → `sdks/`
4. Create umbrella `README.md`

### Phase 2 — Class renaming (internal, no API changes)
1. Rename `Compliance/Saudi/` → `Compliance/Fatoora/`, update all namespaces
2. Rename `Compliance/UAE/` → `Compliance/FTA/`, update all namespaces
3. Rename Zatca-prefixed classes → Fatoora-prefixed
4. Rename UaeFta-prefixed classes → Fta-prefixed
5. Fix `Organization` and `Branch` models (remove `Compliance\Fatoora\DTOs` imports)
6. Add deprecated route aliases

### Phase 3 — New data model
1. Migration: create `organization_groups`, `compliance_profiles`
2. Migration: alter `organizations` (add `group_id`, deprecate `compliance_profile` JSON)
3. Migration: alter `invoices` (add `compliance_profile_id`)
4. Data migration: backfill `compliance_profiles` from existing org `compliance_profile` JSON
5. New models: `OrganizationGroup`, `ComplianceProfile`

### Phase 4 — ComplianceEngine contract + Router
1. Create `Contracts/ComplianceEngine.php`, `Contracts/SubmissionContract.php`, `Contracts/ValidationResult.php`
2. Implement `ComplianceRouter`
3. Implement `FatooraEngine` (wraps existing services)
4. Implement `FtaEngine` (wraps UAE services, corrected to PINT AE)
5. Register engines in `AppServiceProvider`

### Phase 5 — API + Routes
1. New jurisdiction-scoped routes
2. Update controllers
3. Deprecated aliases
4. New org group + compliance profile endpoints

### Phase 6 — Tests + Docs
1. Migrate existing KSA tests to `tests/Feature/Compliance/Fatoora/`
2. Write FTA tests
3. Write router tests
4. Write jurisdiction docs under `docs/sa/` and `docs/ae/`
5. Update memory files

---

## 12. Out of Scope

- PHP top-level namespace rename (`App\` stays)
- Qatar GTA implementation (structure prepared, implementation deferred)
- India GST (separate consideration, not part of this platform)
- B2C UAE invoices (excluded by FTA mandate until further notice)
- ERP ↔ Platform integration (separate spec when needed)
- Payment processing
- Real-time tax calculation

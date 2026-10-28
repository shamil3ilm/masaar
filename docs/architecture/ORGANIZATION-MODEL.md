# Organization Model — Group → Org → Profile Hierarchy

Audience: engineers working on tenancy, sign-up, branches and compliance profiles.

**Models:**
- `app/Domains/Organization/Models/OrganizationGroup.php`
- `app/Domains/Organization/Models/Organization.php`
- `app/Domains/Organization/Models/ComplianceProfile.php`
- `app/Domains/Organization/Models/Branch.php`, `EgsUnit.php` (tills)
- `app/Domains/Organization/Models/Membership.php` (`organization_user`), `BranchAssignment.php` (`branch_user`)

---

## The Hierarchy

```
OrganizationGroup  (optional, for holding companies / franchise groups)
  └── Organization  (legal entity / tenant)
        ├── ComplianceProfile (SA)  ← engine 'fatoora' (ZATCA)
        ├── ComplianceProfile (AE)  ← engine 'peppol' (Peppol PINT AE)
        ├── Membership              ← people and their role (organization_user)
        └── Branch                  ← a location; people assigned via branch_user
              └── EgsUnit (till)    ← one signing device: certificate, counter, chain
```

Head office is a till too, held under the organization's own id with no
branch. A till is never deleted, because the documents it signed name it; it
moves `active` → `retiring` (issues nothing new, files what it signed) →
`retired`, or is marked `lost`. Branches keep the Saudi onboarding state
(`pending`, `csr_generated`, `compliance_passed`, `active`, `suspended`,
`revoked`).
<!-- app/Domains/Organization/Models/EgsUnit.php (ACTIVE, RETIRING, RETIRED, LOST); Branch.php STATUS_* -->

An organization's own `status` is `pending_review`, `active`, `suspended` or
`rejected`; issuing waits on review (`FilingClearance`).
<!-- app/Domains/Organization/Models/Organization.php STATUS_*; app/Domains/Organization/Services/FilingClearance.php -->

An organization is not required to belong to a group. Most single-entity customers will have exactly one organization and one compliance profile.

The profile row allows one profile per jurisdiction, but the issuing flows today
serve an organization in its own `country` only: the UAE routes refuse an
organization whose country is not `AE`. See
[JURISDICTION-ROUTING.md](JURISDICTION-ROUTING.md).

## ComplianceProfile Lifecycle

```
pending_onboarding → active → suspended → revoked
```

Only `active` profiles are returned by `Organization::complianceProfileFor($jurisdiction)`.

## Key Methods

```php
// On Organization:
$org->complianceProfiles()          // HasMany: all profiles
$org->complianceProfileFor('SA')    // ?ComplianceProfile: active SA profile only
$org->group                         // ?OrganizationGroup: parent group (nullable)

// Backward-compat accessors (reads new table first, falls back to JSON):
$org->vat_number                    // active profile for the org's country (settings.vat_number), then compliance_profile JSON, then the column
$org->zatca_onboarded               // reads from active SA profile, then branch fallback

// On ComplianceProfile:
$profile->org                       // BelongsTo Organization
$profile->invoices()                // HasMany Invoice (invoices.profile_id)
$profile->isActive()                // bool
$profile->setting('vat_number')     // mixed: reads key from settings JSON blob
```

## Compliance profiles per jurisdiction

A Saudi profile is created through the compliance-profile API
(`POST /api/organizations/{organization}/compliance-profiles`, an
organization administrator; `ComplianceProfileController`) and then
onboarded with ZATCA:

```php
$org->complianceProfiles()->create([
    'jurisdiction' => 'SA',
    'engine'       => 'fatoora',
    'status'       => 'pending_onboarding',
    'settings'     => ['vat_number' => '300000000000003'],
]);
```

A UAE profile is not created by hand. `PUT /api/compliance/ae/profile`, the
company page, sign-up and the organization API save the seller details
through the UAE module's `SellerRules` and `Support\UaeSellerDetails`, which
creates the profile
active, with engine `peppol`, on the first save. It keeps the UAE tax identity
in `settings`, where `PintMapper` reads it: `trn`, `tin`, `endpoint_id`,
`emirate`, `legal_registration_type`, `legal_registration_id`,
`legal_registration_authority` and `payment_account`. The TRN is also written
to the organization's VAT number, so no two organizations can claim one TRN.
See [the UAE README](../compliance/ae/README.md) and [the UAE integration guide](../integration/UAE.md).

## Backfill Existing Organizations

Existing organizations with a `compliance_profile` JSON blob can be migrated:

```bash
php artisan db:seed --class=BackfillComplianceProfilesSeeder
```

This is idempotent — safe to run multiple times. It was written for legacy
Saudi data. Its engine map gives `fatoora` for `SA` and `peppol` for `AE`
(the engine `UaeSellerDetails` creates); it still carries a `QA` entry for a
country that has no module.
<!-- database/seeders/BackfillComplianceProfilesSeeder.php ENGINE_MAP -->

## Which ZATCA environment

A Saudi organization works in one of ZATCA's three environments - `sandbox`,
`simulation` or `production` - kept in its `compliance_profile` JSON under
`zatca_environment` and defaulting to the deployment's `fatoora.environment`.
Only a platform admin moves it (`EnvironmentSwitch`); the rules for a move
are in [the Saudi README](../compliance/sa/README.md#environments).

Anything that asks whether the deployment files with production asks every
organization, not the default: `FatooraEnvironment::inUse()` lists the
default and each environment an organization has been given. Readiness
checks the CA chain and revocation lists when production is among them, and
`/api/health` reports them as `zatca_environments`.
<!-- app/Domains/Compliance/Countries/SaudiArabia/Rules/FatooraEnvironment.php; Onboarding/EnvironmentSwitch.php -->

## Database Tables

| Table | Purpose |
|-------|---------|
| `organization_groups` | Optional parent groups |
| `organizations` | Legal entities / tenants — has nullable `group_id` FK |
| `compliance_profiles` | Per-jurisdiction settings — one per `(org_id, jurisdiction)` among those not removed (a removed profile is soft-deleted) |
| `invoices` | Has a nullable `profile_id` FK to `compliance_profiles` |
| `peppol_documents` | UAE documents sent and received, with their XML as it travelled; `org_id` FK restricts deletion |
| `branches`, `egs_units` | Locations and the tills that sign for them |
| `organization_user`, `branch_user` | Memberships with their role, and who works at which branch |

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
$org->complianceProfiles()          // HasMany: all profiles
$org->complianceProfileFor('SA')    // ?ComplianceProfile: active SA profile only
$org->group                         // ?OrganizationGroup: parent group (nullable)

// Backward-compat accessors (reads new table first, falls back to JSON):
$org->vat_number                    // reads from active SA profile, then compliance_profile JSON
$org->zatca_onboarded               // reads from active SA profile, then branch fallback

// On ComplianceProfile:
$profile->organization              // BelongsTo Organization
$profile->invoices()                // HasMany Invoice (those submitted via this profile)
$profile->isActive()                // bool
$profile->setting('vat_number')     // mixed: reads key from settings JSON blob
```

## Creating a Multi-Jurisdiction Organization

```php
$org = Organization::create([
    'name'    => 'GCC Operating Co',
    'country' => 'SA',
    'status'  => 'active',
]);

$org->complianceProfiles()->create([
    'jurisdiction' => 'SA',
    'engine'       => 'fatoora',
    'status'       => 'pending_onboarding',
    'settings'     => ['vat_number' => '300000000000003'],
]);

$org->complianceProfiles()->create([
    'jurisdiction' => 'AE',
    'engine'       => 'fta',
    'status'       => 'pending_onboarding',
    'settings'     => ['vat_number' => '100000000000003'],
]);
```

## Backfill Existing Organizations

Existing organizations with a `compliance_profile` JSON blob can be migrated:

```bash
php artisan db:seed --class=BackfillComplianceProfilesSeeder
```

This is idempotent — safe to run multiple times.

## Database Tables

| Table | Purpose |
|-------|---------|
| `organization_groups` | Optional parent groups |
| `organizations` | Legal entities / tenants — has nullable `group_id` FK |
| `compliance_profiles` | Per-jurisdiction settings — unique on `(organization_id, jurisdiction)` |
| `invoices` | Has nullable `compliance_profile_id` FK — set at submission time, permanent audit record |

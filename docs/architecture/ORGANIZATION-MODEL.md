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
        ├── ComplianceProfile (SA)  ← engine 'fatoora' (ZATCA)
        └── ComplianceProfile (AE)  ← engine 'peppol' (Peppol PINT AE)
```

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

A Saudi profile is created through the compliance-profile API and then
onboarded with ZATCA:

```php
$org->complianceProfiles()->create([
    'jurisdiction' => 'SA',
    'engine'       => 'fatoora',
    'status'       => 'pending_onboarding',
    'settings'     => ['vat_number' => '300000000000003'],
]);
```

A UAE profile is not created by hand. `PUT /api/compliance/ae/profile` saves
the seller details through `UaeSellerDetails`, which creates the profile
active, with engine `peppol`, on the first save. It keeps the UAE tax identity
in `settings`, where `PintMapper` reads it: `trn`, `tin`, `endpoint_id`,
`emirate`, `legal_registration_type`, `legal_registration_id`,
`legal_registration_authority` and `payment_account`. The TRN is also written
to the organization's VAT number, so no two organizations can claim one TRN.
See [the UAE integration guide](../ae/INTEGRATION-GUIDE.md).

## Backfill Existing Organizations

Existing organizations with a `compliance_profile` JSON blob can be migrated:

```bash
php artisan db:seed --class=BackfillComplianceProfilesSeeder
```

This is idempotent — safe to run multiple times. It was written for legacy
Saudi data; its engine map still names `fta` for `AE`, a value nothing reads
any more (UAE profiles use `peppol`).

## Database Tables

| Table | Purpose |
|-------|---------|
| `organization_groups` | Optional parent groups |
| `organizations` | Legal entities / tenants — has nullable `group_id` FK |
| `compliance_profiles` | Per-jurisdiction settings — unique on `(org_id, jurisdiction)` |
| `invoices` | Has a nullable `profile_id` FK to `compliance_profiles` |
| `peppol_documents` | UAE documents sent and received, with their XML as it travelled; `org_id` FK restricts deletion |

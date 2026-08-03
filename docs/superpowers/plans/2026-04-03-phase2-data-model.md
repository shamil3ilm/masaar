# Phase 2: Multi-Jurisdiction Data Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce `organization_groups` and `compliance_profiles` tables, wire them into `organizations` and `invoices`, and create the Eloquent models — enabling one organization to hold multiple compliance profiles across jurisdictions (SA, AE, QA, …).

**Architecture:** `OrganizationGroup` (optional holding structure) → `Organization` → `ComplianceProfile` (one per jurisdiction per org). Invoices gain a `compliance_profile_id` FK so submissions know which profile (and therefore which engine) to use. The existing `compliance_profile` JSON column on `organizations` is kept but marked deprecated; a backfill command converts existing rows to the new table. All changes are additive/backward-compatible.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 3, MySQL (prod) / SQLite (test), Eloquent ORM.

---

## File Map

| Action | Path | Purpose |
|--------|------|---------|
| Create | `database/migrations/2026_04_03_000001_create_organization_groups_table.php` | New `organization_groups` table |
| Create | `database/migrations/2026_04_03_000002_create_compliance_profiles_table.php` | New `compliance_profiles` table |
| Create | `database/migrations/2026_04_03_000003_add_group_id_to_organizations_table.php` | Add nullable `group_id` FK to `organizations` |
| Create | `database/migrations/2026_04_03_000004_add_compliance_profile_id_to_invoices_table.php` | Add nullable `compliance_profile_id` FK to `invoices` |
| Create | `app/Domains/Organization/Models/OrganizationGroup.php` | Eloquent model for groups |
| Create | `app/Domains/Organization/Models/ComplianceProfile.php` | Eloquent model for per-jurisdiction compliance |
| Modify | `app/Domains/Organization/Models/Organization.php` | Add `group()`, `complianceProfiles()`, deprecation helpers |
| Create | `database/seeders/BackfillComplianceProfilesSeeder.php` | One-off: JSON → row backfill |
| Create | `tests/Unit/Domains/Organization/OrganizationGroupTest.php` | Unit tests for `OrganizationGroup` |
| Create | `tests/Unit/Domains/Organization/ComplianceProfileTest.php` | Unit tests for `ComplianceProfile` |
| Modify | `tests/Unit/Domains/Organization/OrganizationContextTest.php` | Add group & profile relationship assertions |

---

### Task 1: Migration — `organization_groups` table

**Files:**
- Create: `database/migrations/2026_04_03_000001_create_organization_groups_table.php`
- Test: `tests/Unit/Domains/Organization/OrganizationGroupTest.php` (stub only at this step)

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Domains/Organization/OrganizationGroupTest.php

declare(strict_types=1);

use App\Domains\Organization\Models\OrganizationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an organization group', function () {
    $group = OrganizationGroup::create([
        'name'   => 'ACME Holdings',
        'status' => 'active',
    ]);

    expect($group->id)->toBeString()
        ->and($group->name)->toBe('ACME Holdings')
        ->and($group->status)->toBe('active');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd C:/laragon/www/Masaar
php artisan test tests/Unit/Domains/Organization/OrganizationGroupTest.php --filter "creates an organization group"
```

Expected: FAIL — table `organization_groups` does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_04_03_000001_create_organization_groups_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('status')->default('active'); // active, suspended
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_groups');
    }
};
```

- [ ] **Step 4: Create the model stub** (just enough to make the test pass)

```php
<?php
// app/Domains/Organization/Models/OrganizationGroup.php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationGroup extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'status', 'notes'];

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'group_id');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test tests/Unit/Domains/Organization/OrganizationGroupTest.php --filter "creates an organization group"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/Masaar
git add database/migrations/2026_04_03_000001_create_organization_groups_table.php \
        app/Domains/Organization/Models/OrganizationGroup.php \
        tests/Unit/Domains/Organization/OrganizationGroupTest.php
git commit -m "feat: add organization_groups table and model"
```

---

### Task 2: Migration — `compliance_profiles` table

**Files:**
- Create: `database/migrations/2026_04_03_000002_create_compliance_profiles_table.php`
- Create: `tests/Unit/Domains/Organization/ComplianceProfileTest.php` (stub)

The `compliance_profiles` table holds one row per (organization, jurisdiction) pair. `jurisdiction` is an ISO country code (`SA`, `AE`, `QA`, …). `settings` is a JSON blob whose schema is jurisdiction-specific (VAT number, ZATCA CSIDs, FTA credentials, …).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Domains/Organization/ComplianceProfileTest.php

declare(strict_types=1);

use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a compliance profile for an organization', function () {
    $org = Organization::create([
        'name'    => 'Test Corp',
        'country' => 'SA',
        'status'  => 'active',
    ]);

    $profile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => ['vat_number' => '300000000000003'],
    ]);

    expect($profile->id)->toBeString()
        ->and($profile->jurisdiction)->toBe('SA')
        ->and($profile->engine)->toBe('fatoora')
        ->and($profile->settings['vat_number'])->toBe('300000000000003');
});

it('enforces one profile per organization per jurisdiction', function () {
    $org = Organization::create([
        'name'    => 'Dup Corp',
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

    expect(fn () => ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => [],
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/Domains/Organization/ComplianceProfileTest.php
```

Expected: FAIL — table `compliance_profiles` does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_04_03_000002_create_compliance_profiles_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // ISO 3166-1 alpha-2 country code: SA, AE, QA, …
            $table->string('jurisdiction', 2);

            // Engine slug that ComplianceRouter will resolve: fatoora, fta, gta, …
            $table->string('engine', 32);

            // Profile lifecycle: pending_onboarding | active | suspended | revoked
            $table->string('status', 32)->default('pending_onboarding');

            // Jurisdiction-specific settings blob:
            // SA: vat_number, cr_number, production_csid, compliance_csid, otp, ...
            // AE: vat_number, access_token_url, client_id, client_secret, ...
            $table->json('settings')->nullable();

            $table->timestamps();

            // One active profile per org per jurisdiction
            $table->unique(['organization_id', 'jurisdiction']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_profiles');
    }
};
```

- [ ] **Step 4: Create the `ComplianceProfile` model**

```php
<?php
// app/Domains/Organization/Models/ComplianceProfile.php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceProfile extends Model
{
    use HasUuids;

    public const STATUS_PENDING     = 'pending_onboarding';
    public const STATUS_ACTIVE      = 'active';
    public const STATUS_SUSPENDED   = 'suspended';
    public const STATUS_REVOKED     = 'revoked';

    protected $fillable = [
        'organization_id',
        'jurisdiction',
        'engine',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Get a typed setting value with an optional default.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test tests/Unit/Domains/Organization/ComplianceProfileTest.php
```

Expected: 2 PASS.

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/Masaar
git add database/migrations/2026_04_03_000002_create_compliance_profiles_table.php \
        app/Domains/Organization/Models/ComplianceProfile.php \
        tests/Unit/Domains/Organization/ComplianceProfileTest.php
git commit -m "feat: add compliance_profiles table and model"
```

---

### Task 3: Migration — add `group_id` to `organizations`

**Files:**
- Create: `database/migrations/2026_04_03_000003_add_group_id_to_organizations_table.php`
- Modify: `app/Domains/Organization/Models/Organization.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Domains/Organization/OrganizationGroupTest.php`:

```php
it('links organizations to a group', function () {
    $group = OrganizationGroup::create(['name' => 'Holdings', 'status' => 'active']);

    $org = Organization::create([
        'name'     => 'Sub Corp',
        'country'  => 'SA',
        'status'   => 'active',
        'group_id' => $group->id,
    ]);

    expect($org->group->id)->toBe($group->id)
        ->and($group->organizations()->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/Domains/Organization/OrganizationGroupTest.php --filter "links organizations to a group"
```

Expected: FAIL — column `group_id` does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_04_03_000003_add_group_id_to_organizations_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignUuid('group_id')
                ->nullable()
                ->after('id')
                ->constrained('organization_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropColumn('group_id');
        });
    }
};
```

- [ ] **Step 4: Add `group()` relationship to `Organization`**

In `app/Domains/Organization/Models/Organization.php`, add the import and relationship (keep all existing code intact):

```php
// Add to imports at the top:
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\OrganizationGroup;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Add 'group_id' to $fillable array (existing array, just append):
// 'group_id',

// Add these methods after the existing relationships:

/**
 * The optional holding group this organization belongs to.
 */
public function group(): BelongsTo
{
    return $this->belongsTo(OrganizationGroup::class, 'group_id');
}

/**
 * All compliance profiles for this organization (one per jurisdiction).
 */
public function complianceProfiles(): HasMany
{
    return $this->hasMany(ComplianceProfile::class);
}

/**
 * Get the active compliance profile for a given jurisdiction.
 */
public function complianceProfileFor(string $jurisdiction): ?ComplianceProfile
{
    return $this->complianceProfiles()
        ->where('jurisdiction', $jurisdiction)
        ->where('status', ComplianceProfile::STATUS_ACTIVE)
        ->first();
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test tests/Unit/Domains/Organization/OrganizationGroupTest.php
```

Expected: 2 PASS.

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/Masaar
git add database/migrations/2026_04_03_000003_add_group_id_to_organizations_table.php \
        app/Domains/Organization/Models/Organization.php \
        tests/Unit/Domains/Organization/OrganizationGroupTest.php
git commit -m "feat: add group_id FK to organizations and wire group relationship"
```

---

### Task 4: Migration — add `compliance_profile_id` to `invoices`

**Files:**
- Create: `database/migrations/2026_04_03_000004_add_compliance_profile_id_to_invoices_table.php`
- Modify: `app/Domains/Invoice/Models/Invoice.php`
- Modify: `tests/Unit/Domains/Organization/ComplianceProfileTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Domains/Organization/ComplianceProfileTest.php`:

```php
it('can resolve compliance profile from invoice', function () {
    $org = Organization::create([
        'name'    => 'Invoice Corp',
        'country' => 'SA',
        'status'  => 'active',
    ]);

    $profile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $invoice = \App\Domains\Invoice\Models\Invoice::create([
        'organization_id'      => $org->id,
        'compliance_profile_id' => $profile->id,
        'invoice_number'       => 'INV-0001',
        'type'                 => 'standard',
        'status'               => 'draft',
        'issue_date'           => now()->toDateString(),
        'currency'             => 'SAR',
        'buyer_name'           => 'Buyer Co',
        'subtotal'             => 100,
        'tax_amount'           => 15,
        'total'                => 115,
    ]);

    expect($invoice->complianceProfile->jurisdiction)->toBe('SA')
        ->and($profile->invoices()->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/Domains/Organization/ComplianceProfileTest.php --filter "can resolve compliance profile from invoice"
```

Expected: FAIL — column `compliance_profile_id` does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_04_03_000004_add_compliance_profile_id_to_invoices_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Nullable: existing invoices don't have a profile yet (backfilled separately)
            $table->foreignUuid('compliance_profile_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('compliance_profiles')
                ->nullOnDelete();

            $table->index('compliance_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['compliance_profile_id']);
            $table->dropIndex(['compliance_profile_id']);
            $table->dropColumn('compliance_profile_id');
        });
    }
};
```

- [ ] **Step 4: Add relationship to `Invoice` model**

Open `app/Domains/Invoice/Models/Invoice.php`. Add:

```php
// Add import:
use App\Domains\Organization\Models\ComplianceProfile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Add to $fillable: 'compliance_profile_id',

// Add method:
public function complianceProfile(): BelongsTo
{
    return $this->belongsTo(ComplianceProfile::class);
}
```

- [ ] **Step 5: Run all compliance profile tests**

```bash
php artisan test tests/Unit/Domains/Organization/ComplianceProfileTest.php
```

Expected: 3 PASS.

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/Masaar
git add database/migrations/2026_04_03_000004_add_compliance_profile_id_to_invoices_table.php \
        app/Domains/Invoice/Models/Invoice.php \
        tests/Unit/Domains/Organization/ComplianceProfileTest.php
git commit -m "feat: add compliance_profile_id FK to invoices"
```

---

### Task 5: Backfill seeder — JSON compliance_profile → compliance_profiles row

**Files:**
- Create: `database/seeders/BackfillComplianceProfilesSeeder.php`
- Modify: `tests/Unit/Domains/Organization/ComplianceProfileTest.php`

This seeder converts the legacy `organizations.compliance_profile` JSON column into proper `compliance_profiles` rows. Safe to run multiple times (idempotent via `firstOrCreate`).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Domains/Organization/ComplianceProfileTest.php`:

```php
it('backfill seeder converts legacy JSON to compliance profile row', function () {
    // Org with legacy JSON compliance_profile (no ComplianceProfile row yet)
    $org = Organization::create([
        'name'               => 'Legacy Corp',
        'country'            => 'SA',
        'status'             => 'active',
        'compliance_profile' => [
            'vat_number'        => '300000000000003',
            'zatca_onboarded'   => true,
            'production_csid'   => 'csid-prod-abc',
            'compliance_csid'   => 'csid-comp-xyz',
        ],
    ]);

    expect(ComplianceProfile::where('organization_id', $org->id)->count())->toBe(0);

    $seeder = new \Database\Seeders\BackfillComplianceProfilesSeeder();
    $seeder->run();

    $profile = ComplianceProfile::where('organization_id', $org->id)
        ->where('jurisdiction', 'SA')
        ->first();

    expect($profile)->not->toBeNull()
        ->and($profile->engine)->toBe('fatoora')
        ->and($profile->status)->toBe(ComplianceProfile::STATUS_ACTIVE)
        ->and($profile->setting('vat_number'))->toBe('300000000000003')
        ->and($profile->setting('production_csid'))->toBe('csid-prod-abc');
});

it('backfill seeder is idempotent', function () {
    $org = Organization::create([
        'name'               => 'Idem Corp',
        'country'            => 'SA',
        'status'             => 'active',
        'compliance_profile' => ['vat_number' => '300000000000003'],
    ]);

    $seeder = new \Database\Seeders\BackfillComplianceProfilesSeeder();
    $seeder->run();
    $seeder->run(); // second run should not create duplicates

    expect(ComplianceProfile::where('organization_id', $org->id)->count())->toBe(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/Domains/Organization/ComplianceProfileTest.php --filter "backfill"
```

Expected: FAIL — class `BackfillComplianceProfilesSeeder` not found.

- [ ] **Step 3: Create the seeder**

```php
<?php
// database/seeders/BackfillComplianceProfilesSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * One-time backfill: converts legacy organizations.compliance_profile JSON
 * into proper compliance_profiles rows.
 *
 * Safe to run multiple times (idempotent via firstOrCreate).
 * Run via: php artisan db:seed --class=BackfillComplianceProfilesSeeder
 */
class BackfillComplianceProfilesSeeder extends Seeder
{
    /**
     * Engine map: ISO country code → engine slug.
     */
    private const ENGINE_MAP = [
        'SA' => 'fatoora',
        'AE' => 'fta',
        'QA' => 'gta',
    ];

    public function run(): void
    {
        Organization::whereNotNull('compliance_profile')
            ->each(function (Organization $org) {
                $jurisdiction = $org->country ?? 'SA';
                $engine = self::ENGINE_MAP[$jurisdiction] ?? 'fatoora';
                $legacy = $org->compliance_profile ?? [];

                // Skip if nothing useful in the JSON
                if (empty($legacy)) {
                    return;
                }

                $isOnboarded = (bool) ($legacy['zatca_onboarded']
                    ?? $legacy['fta_onboarded']
                    ?? false);

                ComplianceProfile::firstOrCreate(
                    [
                        'organization_id' => $org->id,
                        'jurisdiction'    => $jurisdiction,
                    ],
                    [
                        'engine'   => $engine,
                        'status'   => $isOnboarded
                            ? ComplianceProfile::STATUS_ACTIVE
                            : ComplianceProfile::STATUS_PENDING,
                        'settings' => $legacy,
                    ]
                );
            });
    }
}
```

- [ ] **Step 4: Run backfill tests**

```bash
php artisan test tests/Unit/Domains/Organization/ComplianceProfileTest.php --filter "backfill"
```

Expected: 2 PASS.

- [ ] **Step 5: Run all compliance profile tests to check for regressions**

```bash
php artisan test tests/Unit/Domains/Organization/ComplianceProfileTest.php
```

Expected: 5 PASS.

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/Masaar
git add database/seeders/BackfillComplianceProfilesSeeder.php \
        tests/Unit/Domains/Organization/ComplianceProfileTest.php
git commit -m "feat: backfill seeder for legacy compliance_profile JSON → compliance_profiles rows"
```

---

### Task 6: `Organization` model — backward-compatible deprecation helpers

**Files:**
- Modify: `app/Domains/Organization/Models/Organization.php`
- Modify: `tests/Unit/Domains/Organization/OrganizationContextTest.php`

The existing `getZatcaOnboardedAttribute`, `getVatNumberAttribute`, and `hasCompleteZatcaProfile()` must still work but now read from `ComplianceProfile` when available, falling back to the JSON column for backward compatibility.

- [ ] **Step 1: Write failing tests**

Add to `tests/Unit/Domains/Organization/OrganizationContextTest.php`:

```php
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns vat number from compliance profile when present', function () {
    $org = Organization::create([
        'name'    => 'Corp A',
        'country' => 'SA',
        'status'  => 'active',
    ]);

    ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => ComplianceProfile::STATUS_ACTIVE,
        'settings'        => ['vat_number' => '300000000000099'],
    ]);

    expect($org->fresh()->vat_number)->toBe('300000000000099');
});

it('falls back to legacy JSON for vat number when no compliance profile', function () {
    $org = Organization::create([
        'name'               => 'Corp B',
        'country'            => 'SA',
        'status'             => 'active',
        'compliance_profile' => ['vat_number' => '300000000000077'],
    ]);

    expect($org->vat_number)->toBe('300000000000077');
});

it('complianceProfileFor returns active profile for jurisdiction', function () {
    $org = Organization::create([
        'name'    => 'Corp C',
        'country' => 'SA',
        'status'  => 'active',
    ]);

    ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => ComplianceProfile::STATUS_ACTIVE,
        'settings'        => [],
    ]);

    $profile = $org->complianceProfileFor('SA');
    expect($profile)->not->toBeNull()
        ->and($profile->engine)->toBe('fatoora');
});

it('complianceProfileFor returns null for unknown jurisdiction', function () {
    $org = Organization::create([
        'name'    => 'Corp D',
        'country' => 'SA',
        'status'  => 'active',
    ]);

    expect($org->complianceProfileFor('QA'))->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/Domains/Organization/OrganizationContextTest.php --filter "vat number|complianceProfileFor"
```

Expected: FAIL (no `complianceProfiles` relationship yet on model, or logic not updated).

- [ ] **Step 3: Update `Organization` model**

Replace `getVatNumberAttribute` and related helpers in `app/Domains/Organization/Models/Organization.php` with:

```php
/**
 * Get VAT number — prefers active ComplianceProfile, falls back to legacy JSON.
 */
public function getVatNumberAttribute(): ?string
{
    $profile = $this->complianceProfileFor($this->country ?? 'SA');

    return $profile?->setting('vat_number')
        ?? $this->compliance_profile['vat_number']
        ?? null;
}

/**
 * @deprecated Use complianceProfileFor('SA')->isActive() instead.
 */
public function getZatcaOnboardedAttribute(): bool
{
    $profile = $this->complianceProfileFor('SA');

    if ($profile !== null) {
        return $profile->isActive();
    }

    // Legacy fallback
    if ($this->compliance_profile['zatca_onboarded'] ?? false) {
        return true;
    }

    return $this->branches()->where('onboarding_status', Branch::STATUS_ACTIVE)->exists();
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Unit/Domains/Organization/OrganizationContextTest.php
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/Masaar
git add app/Domains/Organization/Models/Organization.php \
        tests/Unit/Domains/Organization/OrganizationContextTest.php
git commit -m "feat: update Organization model — read from ComplianceProfile with JSON fallback"
```

---

### Task 7: Full test suite pass + smoke check

**Files:**
- Read-only test run — no new files

- [ ] **Step 1: Run all Organization + Compliance unit tests**

```bash
php artisan test tests/Unit/Domains/Organization/ tests/Unit/Domains/Compliance/ --verbose
```

Expected: all green.

- [ ] **Step 2: Run smoke test**

```bash
php artisan test tests/Feature/Compliance/SmokeTest.php --verbose
```

Expected: 13 PASS.

- [ ] **Step 3: Run full test suite**

```bash
php artisan test
```

Expected: all green (no regressions).

- [ ] **Step 4: Verify migrations run clean on fresh DB**

```bash
php artisan migrate:fresh --env=testing
```

Expected: Migrations ran successfully.

- [ ] **Step 5: Commit (if any fixup needed)**

```bash
cd C:/laragon/www/Masaar
git add -p  # stage only fixups if any
git commit -m "fix: post-Phase2 test suite cleanup"
```

---

## Self-Review

### Spec Coverage

| Spec requirement | Task |
|-----------------|------|
| `organization_groups` table | Task 1 |
| `compliance_profiles` table with per-jurisdiction row | Task 2 |
| FK: `organizations.group_id` | Task 3 |
| FK: `invoices.compliance_profile_id` | Task 4 |
| Backfill existing SA orgs from JSON | Task 5 |
| `Organization.complianceProfileFor(jurisdiction)` | Task 3 + Task 6 |
| Backward compat: `vat_number`, `zatca_onboarded` | Task 6 |
| `ComplianceProfile.setting(key, default)` helper | Task 2 |
| One-profile-per-org-per-jurisdiction unique constraint | Task 2 |

### Placeholder Check

- No TBDs, no "similar to Task N" patterns.
- Every step shows complete code.

### Type Consistency

- `ComplianceProfile::STATUS_*` constants defined in Task 2, used in Tasks 5 and 6.
- `complianceProfileFor()` defined in Task 3 (migration) + `Organization` model, called in Task 6.
- `ComplianceProfile::setting()` defined in Task 2, called in Task 5 tests.
- `Invoice.complianceProfile()` relationship defined in Task 4, tested in Task 4.

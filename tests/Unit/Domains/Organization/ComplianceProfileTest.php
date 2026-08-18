<?php

declare(strict_types=1);

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Database\Seeders\BackfillComplianceProfilesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a compliance profile for an organization', function () {
    $org = Organization::create([
        'name' => 'Test Corp',
        'country' => 'SA',
        'status' => 'active',
    ]);

    $profile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction' => 'SA',
        'engine' => 'fatoora',
        'status' => 'active',
        'settings' => ['vat_number' => '300000000000003'],
    ]);

    expect($profile->id)->toBeString()
        ->and($profile->jurisdiction)->toBe('SA')
        ->and($profile->engine)->toBe('fatoora')
        ->and($profile->settings['vat_number'])->toBe('300000000000003');
});

it('enforces one profile per organization per jurisdiction', function () {
    $org = Organization::create([
        'name' => 'Dup Corp',
        'country' => 'SA',
        'status' => 'active',
    ]);

    ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction' => 'SA',
        'engine' => 'fatoora',
        'status' => 'active',
        'settings' => [],
    ]);

    expect(fn () => ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction' => 'SA',
        'engine' => 'fatoora',
        'status' => 'active',
        'settings' => [],
    ]))->toThrow(QueryException::class);
});

it('can resolve compliance profile from invoice', function () {
    $org = Organization::create([
        'name' => 'Invoice Corp',
        'country' => 'SA',
        'status' => 'active',
    ]);

    $profile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction' => 'SA',
        'engine' => 'fatoora',
        'status' => 'active',
        'settings' => [],
    ]);

    $invoice = Invoice::create([
        'organization_id' => $org->id,
        'compliance_profile_id' => $profile->id,
        'invoice_number' => 'INV-0001',
        'type' => 'standard',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'SAR',
        'buyer_name' => 'Buyer Co',
        'subtotal' => 100,
        'tax_amount' => 15,
        'total' => 115,
    ]);

    expect($invoice->complianceProfile->jurisdiction)->toBe('SA')
        ->and($profile->invoices()->count())->toBe(1);
});

it('backfill seeder converts legacy JSON to compliance profile row', function () {
    // Org with legacy JSON compliance_profile (no ComplianceProfile row yet)
    $org = Organization::create([
        'name' => 'Legacy Corp',
        'country' => 'SA',
        'status' => 'active',
        'compliance_profile' => [
            'vat_number' => '300000000000003',
            'zatca_onboarded' => true,
            'production_csid' => 'csid-prod-abc',
            'compliance_csid' => 'csid-comp-xyz',
        ],
    ]);

    expect(ComplianceProfile::where('organization_id', $org->id)->count())->toBe(0);

    $seeder = new BackfillComplianceProfilesSeeder;
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
        'name' => 'Idem Corp',
        'country' => 'SA',
        'status' => 'active',
        'compliance_profile' => ['vat_number' => '300000000000003'],
    ]);

    $seeder = new BackfillComplianceProfilesSeeder;
    $seeder->run();
    $seeder->run(); // second run should not create duplicates

    expect(ComplianceProfile::where('organization_id', $org->id)->count())->toBe(1);
});

<?php

declare(strict_types=1);

use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns vat number from compliance profile when present', function () {
    $org = Organization::create([
        'name' => 'Corp A',
        'country' => 'SA',
        'status' => 'active',
    ]);

    ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction' => 'SA',
        'engine' => 'fatoora',
        'status' => ComplianceProfile::STATUS_ACTIVE,
        'settings' => ['vat_number' => '300000000000099'],
    ]);

    expect($org->fresh()->vat_number)->toBe('300000000000099');
});

it('falls back to legacy JSON for vat number when no compliance profile', function () {
    $org = Organization::create([
        'name' => 'Corp B',
        'country' => 'SA',
        'status' => 'active',
        'compliance_profile' => ['vat_number' => '300000000000077'],
    ]);

    expect($org->vat_number)->toBe('300000000000077');
});

it('complianceProfileFor returns active profile for jurisdiction', function () {
    $org = Organization::create([
        'name' => 'Corp C',
        'country' => 'SA',
        'status' => 'active',
    ]);

    ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction' => 'SA',
        'engine' => 'fatoora',
        'status' => ComplianceProfile::STATUS_ACTIVE,
        'settings' => [],
    ]);

    $profile = $org->complianceProfileFor('SA');
    expect($profile)->not->toBeNull()
        ->and($profile->engine)->toBe('fatoora');
});

it('complianceProfileFor returns null for unknown jurisdiction', function () {
    $org = Organization::create([
        'name' => 'Corp D',
        'country' => 'SA',
        'status' => 'active',
    ]);

    expect($org->complianceProfileFor('QA'))->toBeNull();
});

<?php

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

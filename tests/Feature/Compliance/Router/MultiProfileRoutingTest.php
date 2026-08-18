<?php

declare(strict_types=1);

use App\Domains\Compliance\ComplianceRouter;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\FTA\FtaEngine;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('routes correctly for an org with both SA and AE profiles', function () {
    $org = Organization::create(['name' => 'Multinational Corp', 'country' => 'SA', 'status' => 'active']);

    $saProfile = ComplianceProfile::create([
        'org_id' => $org->id, 'jurisdiction' => 'SA',
        'engine' => 'fatoora', 'status' => 'active', 'settings' => [],
    ]);
    $aeProfile = ComplianceProfile::create([
        'org_id' => $org->id, 'jurisdiction' => 'AE',
        'engine' => 'fta', 'status' => 'active', 'settings' => [],
    ]);

    $router = app(ComplianceRouter::class);

    expect($router->engineFor($saProfile))->toBeInstanceOf(FatooraEngine::class)
        ->and($router->engineFor($aeProfile))->toBeInstanceOf(FtaEngine::class);
});

it('complianceProfileFor returns correct profile per jurisdiction', function () {
    $org = Organization::create(['name' => 'Dual Corp', 'country' => 'SA', 'status' => 'active']);

    ComplianceProfile::create([
        'org_id' => $org->id, 'jurisdiction' => 'SA',
        'engine' => 'fatoora', 'status' => 'active', 'settings' => [],
    ]);
    ComplianceProfile::create([
        'org_id' => $org->id, 'jurisdiction' => 'AE',
        'engine' => 'fta', 'status' => 'active', 'settings' => [],
    ]);

    $saProfile = $org->complianceProfileFor('SA');
    $aeProfile = $org->complianceProfileFor('AE');
    $qaProfile = $org->complianceProfileFor('QA');

    expect($saProfile?->engine)->toBe('fatoora')
        ->and($aeProfile?->engine)->toBe('fta')
        ->and($qaProfile)->toBeNull();
});

it('suspended profile is not returned by complianceProfileFor', function () {
    $org = Organization::create(['name' => 'Suspended Corp', 'country' => 'SA', 'status' => 'active']);

    ComplianceProfile::create([
        'org_id' => $org->id, 'jurisdiction' => 'SA',
        'engine' => 'fatoora', 'status' => 'suspended', 'settings' => [],
    ]);

    expect($org->complianceProfileFor('SA'))->toBeNull();
});

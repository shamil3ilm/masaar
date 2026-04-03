<?php

declare(strict_types=1);

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\FTA\FtaEngine;
use App\Domains\Compliance\Contracts\ComplianceEngine as EngineContract;
use App\Domains\Compliance\ComplianceRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ComplianceEngine interface is defined', function () {
    expect(interface_exists(ComplianceEngine::class))->toBeTrue();
});

it('SubmissionResult can be constructed', function () {
    $result = new SubmissionResult(
        success: true,
        submissionId: 'sub-001',
        referenceId: 'ref-001',
        status: 'accepted',
        rawResponse: ['key' => 'value'],
        errorMessage: null,
    );

    expect($result->success)->toBeTrue()
        ->and($result->submissionId)->toBe('sub-001')
        ->and($result->status)->toBe('accepted');
});

it('ValidationResult can be constructed', function () {
    $result = new ValidationResult(
        valid: false,
        errors: ['Missing VAT number'],
        warnings: [],
    );

    expect($result->valid)->toBeFalse()
        ->and($result->errors)->toHaveCount(1);
});

it('FatooraEngine implements ComplianceEngine', function () {
    expect(is_a(FatooraEngine::class, EngineContract::class, true))->toBeTrue();
});

it('FatooraEngine supports SA jurisdiction only', function () {
    $engine = app(FatooraEngine::class);

    expect($engine->supports('SA'))->toBeTrue()
        ->and($engine->supports('AE'))->toBeFalse()
        ->and($engine->supports('QA'))->toBeFalse();
});

it('FtaEngine implements ComplianceEngine', function () {
    expect(is_a(FtaEngine::class, EngineContract::class, true))->toBeTrue();
});

it('FtaEngine supports AE jurisdiction only', function () {
    $engine = app(FtaEngine::class);

    expect($engine->supports('AE'))->toBeTrue()
        ->and($engine->supports('SA'))->toBeFalse()
        ->and($engine->supports('QA'))->toBeFalse();
});

it('ComplianceRouter resolves FatooraEngine for SA profile', function () {
    $org = \App\Domains\Organization\Models\Organization::create([
        'name' => 'SA Corp', 'country' => 'SA', 'status' => 'active',
    ]);

    $profile = \App\Domains\Organization\Models\ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $router = app(ComplianceRouter::class);
    $engine = $router->engineFor($profile);

    expect($engine)->toBeInstanceOf(\App\Domains\Compliance\Fatoora\FatooraEngine::class);
});

it('ComplianceRouter resolves FtaEngine for AE profile', function () {
    $org = \App\Domains\Organization\Models\Organization::create([
        'name' => 'AE Corp', 'country' => 'AE', 'status' => 'active',
    ]);

    $profile = \App\Domains\Organization\Models\ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'AE',
        'engine'          => 'fta',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $router = app(ComplianceRouter::class);
    $engine = $router->engineFor($profile);

    expect($engine)->toBeInstanceOf(\App\Domains\Compliance\FTA\FtaEngine::class);
});

it('ComplianceRouter throws for unknown jurisdiction', function () {
    $org = \App\Domains\Organization\Models\Organization::create([
        'name' => 'QA Corp', 'country' => 'QA', 'status' => 'active',
    ]);

    $profile = \App\Domains\Organization\Models\ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'QA',
        'engine'          => 'gta',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $router = app(ComplianceRouter::class);

    expect(fn () => $router->engineFor($profile))
        ->toThrow(\App\Domains\Compliance\Exceptions\UnsupportedJurisdictionException::class);
});

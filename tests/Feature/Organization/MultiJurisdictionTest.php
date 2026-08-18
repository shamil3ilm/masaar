<?php

declare(strict_types=1);

use App\Domains\Compliance\ComplianceRouter;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\FTA\FtaEngine;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('an org in a group with two profiles routes to the right engine per profile', function () {
    $group = OrganizationGroup::create(['name' => 'GCC Holdings', 'status' => 'active']);

    $org = Organization::create([
        'name' => 'GCC Operating Co', 'country' => 'SA', 'status' => 'active', 'group_id' => $group->id,
    ]);

    $saProfile = ComplianceProfile::create([
        'organization_id' => $org->id, 'jurisdiction' => 'SA',
        'engine' => 'fatoora', 'status' => 'active', 'settings' => [],
    ]);
    $aeProfile = ComplianceProfile::create([
        'organization_id' => $org->id, 'jurisdiction' => 'AE',
        'engine' => 'fta', 'status' => 'active', 'settings' => [],
    ]);

    expect($org->group->id)->toBe($group->id)
        ->and($group->organizations()->count())->toBe(1);

    $router = app(ComplianceRouter::class);

    expect($router->engineFor($saProfile))->toBeInstanceOf(FatooraEngine::class)
        ->and($router->engineFor($aeProfile))->toBeInstanceOf(FtaEngine::class);
});

it('invoice can be linked to a compliance profile', function () {
    $org = Organization::create(['name' => 'Invoice Linker Corp', 'country' => 'SA', 'status' => 'active']);

    $profile = ComplianceProfile::create([
        'organization_id' => $org->id, 'jurisdiction' => 'SA',
        'engine' => 'fatoora', 'status' => 'active', 'settings' => [],
    ]);

    $invoice = Invoice::create([
        'organization_id' => $org->id,
        'compliance_profile_id' => $profile->id,
        'invoice_number' => 'INV-MJ-001',
        'type' => 'standard',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'SAR',
        'buyer_name' => 'Buyer Co',
    ]);

    expect($invoice->complianceProfile->jurisdiction)->toBe('SA')
        ->and($invoice->complianceProfile->engine)->toBe('fatoora');
});

it('mock submit routes correctly per jurisdiction', function () {
    $org = Organization::create(['name' => 'Mock Submit Corp', 'country' => 'AE', 'status' => 'active']);

    $aeProfile = ComplianceProfile::create([
        'organization_id' => $org->id, 'jurisdiction' => 'AE',
        'engine' => 'fta', 'status' => 'active', 'settings' => [],
    ]);

    $invoice = Invoice::create([
        'organization_id' => $org->id,
        'invoice_number' => 'INV-AE-001',
        'type' => 'standard',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'SAR',
        'buyer_name' => 'UAE Buyer',
    ]);

    $mockEngine = Mockery::mock(FtaEngine::class);
    $mockEngine->allows('supports')->with('AE')->andReturns(true);
    $mockEngine->expects('submit')->once()->andReturns(
        new SubmissionResult(true, 'fta-sub-001', 'peppol-ref-001', 'queued', [], null)
    );

    $router = new ComplianceRouter([$mockEngine]);
    $result = $router->submit($invoice, $aeProfile);

    expect($result->success)->toBeTrue()
        ->and($result->submissionId)->toBe('fta-sub-001');
});

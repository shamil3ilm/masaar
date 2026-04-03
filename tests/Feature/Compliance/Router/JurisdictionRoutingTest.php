<?php

declare(strict_types=1);

use App\Domains\Compliance\ComplianceRouter;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\Exceptions\UnsupportedJurisdictionException;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\FTA\FtaEngine;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeComplianceProfile(string $jurisdiction, string $engine): ComplianceProfile
{
    $org = Organization::create([
        'name'    => "{$jurisdiction} Corp",
        'country' => $jurisdiction,
        'status'  => 'active',
    ]);

    return ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => $jurisdiction,
        'engine'          => $engine,
        'status'          => 'active',
        'settings'        => [],
    ]);
}

function makeInvoice(string $organizationId): Invoice
{
    return Invoice::create([
        'organization_id' => $organizationId,
        'invoice_number'  => 'INV-' . uniqid(),
        'type'            => 'standard',
        'status'          => 'draft',
        'issue_date'      => now()->toDateString(),
        'currency'        => 'SAR',
        'buyer_name'      => 'Test Buyer',
    ]);
}

it('routes SA profile to FatooraEngine', function () {
    $profile = makeComplianceProfile('SA', 'fatoora');
    $router  = app(ComplianceRouter::class);

    expect($router->engineFor($profile))->toBeInstanceOf(FatooraEngine::class);
});

it('routes AE profile to FtaEngine', function () {
    $profile = makeComplianceProfile('AE', 'fta');
    $router  = app(ComplianceRouter::class);

    expect($router->engineFor($profile))->toBeInstanceOf(FtaEngine::class);
});

it('throws UnsupportedJurisdictionException for unregistered jurisdiction', function () {
    $profile = makeComplianceProfile('QA', 'gta');
    $router  = app(ComplianceRouter::class);

    expect(fn () => $router->engineFor($profile))
        ->toThrow(UnsupportedJurisdictionException::class);
});

it('routes submit call through to the correct engine', function () {
    $profile = makeComplianceProfile('SA', 'fatoora');
    $invoice = makeInvoice($profile->organization_id);

    $mockEngine = Mockery::mock(FatooraEngine::class);
    $mockEngine->allows('supports')->with('SA')->andReturns(true);
    $mockEngine->expects('submit')->once()->andReturns(
        new SubmissionResult(true, 'sub-001', 'ref-001', 'accepted', [], null)
    );

    $router = new ComplianceRouter([$mockEngine]);
    $result = $router->submit($invoice, $profile);

    expect($result->success)->toBeTrue()
        ->and($result->submissionId)->toBe('sub-001');
});

it('routes validate call through to the correct engine', function () {
    $profile = makeComplianceProfile('AE', 'fta');
    $invoice = makeInvoice($profile->organization_id);

    $mockEngine = Mockery::mock(FtaEngine::class);
    $mockEngine->allows('supports')->with('AE')->andReturns(true);
    $mockEngine->expects('validate')->once()->andReturns(
        ValidationResult::pass(['advisory: B2C invoices not submitted'])
    );

    $router = new ComplianceRouter([$mockEngine]);
    $result = $router->validate($invoice, $profile);

    expect($result->valid)->toBeTrue();
});

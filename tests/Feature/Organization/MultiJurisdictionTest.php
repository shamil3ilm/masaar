<?php

declare(strict_types=1);

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('an org in a group keeps one profile per jurisdiction', function () {
    $group = OrganizationGroup::create(['name' => 'GCC Holdings', 'status' => 'active']);

    $org = Organization::create([
        'name' => 'GCC Operating Co', 'country' => 'SA', 'status' => 'active', 'group_id' => $group->id,
    ]);

    $saProfile = ComplianceProfile::create([
        'org_id' => $org->id, 'jurisdiction' => 'SA',
        'engine' => 'fatoora', 'status' => 'active', 'settings' => [],
    ]);
    $aeProfile = ComplianceProfile::create([
        'org_id' => $org->id, 'jurisdiction' => 'AE',
        'engine' => 'peppol', 'status' => 'active', 'settings' => [],
    ]);

    expect($org->group->id)->toBe($group->id)
        ->and($group->organizations()->count())->toBe(1)
        ->and($org->complianceProfileFor('SA')?->id)->toBe($saProfile->id)
        ->and($org->complianceProfileFor('AE')?->id)->toBe($aeProfile->id);
});

it('invoice can be linked to a compliance profile', function () {
    $org = Organization::create(['name' => 'Invoice Linker Corp', 'country' => 'SA', 'status' => 'active']);

    $profile = ComplianceProfile::create([
        'org_id' => $org->id, 'jurisdiction' => 'SA',
        'engine' => 'fatoora', 'status' => 'active', 'settings' => [],
    ]);

    $invoice = Invoice::create([
        'org_id' => $org->id,
        'profile_id' => $profile->id,
        'invoice_number' => 'INV-MJ-001',
        'type' => 'standard',
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'currency' => 'SAR',
        'buyer_name' => 'Buyer Co',
    ]);

    expect($invoice->profile->jurisdiction)->toBe('SA')
        ->and($invoice->profile->engine)->toBe('fatoora');
});

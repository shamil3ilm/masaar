<?php

declare(strict_types=1);

use App\Domains\Organization\Models\Organization;
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

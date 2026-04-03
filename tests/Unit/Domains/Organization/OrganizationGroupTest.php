<?php

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

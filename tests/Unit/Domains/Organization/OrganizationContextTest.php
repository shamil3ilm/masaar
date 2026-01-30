<?php

use App\Domains\Organization\ValueObjects\OrganizationContext;

it('creates context from claims', function () {
    $context = OrganizationContext::fromClaims([
        'org_id' => 'org-123',
        'role' => 'admin',
    ]);

    expect($context->organizationId)->toBe('org-123');
    expect($context->role)->toBe('admin');
});

it('detects admin role', function () {
    $admin = new OrganizationContext('org-1', 'admin');
    $member = new OrganizationContext('org-2', 'member');

    expect($admin->isAdmin())->toBeTrue();
    expect($member->isAdmin())->toBeFalse();
});

it('checks role correctly', function () {
    $context = new OrganizationContext('org-1', 'member');

    expect($context->hasRole('member'))->toBeTrue();
    expect($context->hasRole('admin'))->toBeFalse();
});

it('converts to array for JWT claims', function () {
    $context = new OrganizationContext('org-123', 'admin');

    expect($context->toArray())->toBe([
        'org_id' => 'org-123',
        'role' => 'admin',
    ]);
});

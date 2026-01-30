<?php

use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;

it('starts with no context', function () {
    $resolver = new TenantResolver();

    expect($resolver->hasContext())->toBeFalse();
    expect($resolver->getContext())->toBeNull();
    expect($resolver->getOrganizationId())->toBeNull();
});

it('stores and retrieves context', function () {
    $resolver = new TenantResolver();
    $context = new OrganizationContext('org-123', 'admin');

    $resolver->setContext($context);

    expect($resolver->hasContext())->toBeTrue();
    expect($resolver->getContext())->toBe($context);
    expect($resolver->getOrganizationId())->toBe('org-123');
    expect($resolver->getRole())->toBe('admin');
});

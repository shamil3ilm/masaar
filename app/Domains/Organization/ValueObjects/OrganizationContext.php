<?php

declare(strict_types=1);

namespace App\Domains\Organization\ValueObjects;

/**
 * Value object representing the current organization context.
 *
 * Extracted from JWT claims or request headers.
 * Immutable and used for scoping queries and authorization.
 */
final readonly class OrganizationContext
{
    public function __construct(
        public string $organizationId,
        public string $role,
    ) {}

    public static function fromClaims(array $claims): self
    {
        return new self(
            organizationId: $claims['org_id'],
            role: $claims['role'],
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }
}

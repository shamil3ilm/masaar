<?php

declare(strict_types=1);

namespace App\Domains\Organization\ValueObjects;

/**
 * Immutable value object representing the current organization context.
 *
 * Extracted from JWT claims at request time.
 * Used for scoping queries and authorization checks.
 */
final readonly class OrganizationContext
{
    public function __construct(
        public string $organizationId,
        public string $role,
    ) {}

    /**
     * Create from JWT claims array.
     */
    public static function fromClaims(array $claims): self
    {
        return new self(
            organizationId: $claims['org_id'],
            role: $claims['role'],
        );
    }

    /**
     * Context for a machine credential — an API key or a licence.
     *
     * These authenticate an integration rather than a person, so they carry no
     * organization role. They still establish the tenant, which is what query
     * scoping depends on.
     */
    public static function forMachine(string $organizationId): self
    {
        return new self(organizationId: $organizationId, role: 'service');
    }

    /**
     * Check if user is admin in this organization.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Convert to array (for JWT claims).
     */
    public function toArray(): array
    {
        return [
            'org_id' => $this->organizationId,
            'role' => $this->role,
        ];
    }
}

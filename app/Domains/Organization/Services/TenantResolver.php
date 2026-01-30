<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\ValueObjects\OrganizationContext;

/**
 * Resolves the current tenant (organization) context.
 *
 * Used to scope queries and enforce tenant isolation.
 * Context comes from JWT claims set at login time.
 */
class TenantResolver
{
    private ?OrganizationContext $context = null;

    /**
     * Set the current organization context (called after JWT validation).
     */
    public function setContext(OrganizationContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Get the current organization context.
     */
    public function getContext(): ?OrganizationContext
    {
        return $this->context;
    }

    /**
     * Get the current organization ID.
     */
    public function getOrganizationId(): ?string
    {
        return $this->context?->organizationId;
    }

    /**
     * Get the current user's role in the organization.
     */
    public function getRole(): ?string
    {
        return $this->context?->role;
    }

    /**
     * Check if a tenant context is active.
     */
    public function hasContext(): bool
    {
        return $this->context !== null;
    }

    /**
     * Load and return the full Organization model.
     */
    public function getOrganization(): ?Organization
    {
        if (! $this->hasContext()) {
            return null;
        }

        return Organization::find($this->context->organizationId);
    }
}

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
     * Run a callback acting as one organization.
     *
     * Commands and queue workers have no credential to derive a tenant from,
     * so TenantScope leaves them unscoped. Work that concerns a single
     * organization should say so and be held to it: inside this callback the
     * scope applies exactly as it would during a request, so a query that
     * forgets its filter returns that tenant's rows rather than everyone's.
     *
     *     $tenants->runAs($orgId, fn () => $this->process($orgId));
     *
     * The previous context is restored afterwards, so a loop over tenants
     * cannot leak one iteration's context into the next.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(string $organizationId, callable $callback): mixed
    {
        $previous = $this->context;
        $this->context = OrganizationContext::forMachine($organizationId);

        try {
            return $callback();
        } finally {
            $this->context = $previous;
        }
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

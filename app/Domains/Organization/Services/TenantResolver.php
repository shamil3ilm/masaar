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
     * The commands and jobs running now, innermost last: each one's name,
     * and whether it declared that it runs across tenants.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private array $work = [];

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
     * so one that concerns a single organization says so here and is held
     * to it: inside this callback the scope applies exactly as it would
     * during a request, so a query that forgets its filter returns that
     * tenant's rows rather than everyone's. Without it - and without
     * #[RunsAcrossTenants] - its scoped queries are refused.
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
     * The command or job running now that has declared neither the tenant it
     * acts for nor that it runs across tenants; null outside one, or when it
     * has. TenantScope refuses its scoped queries.
     */
    public function undeclaredWork(): ?string
    {
        if ($this->work === []) {
            return null;
        }

        [$name, $acrossTenants] = $this->work[array_key_last($this->work)];

        return $acrossTenants ? null : $name;
    }

    /** A command or job has started (TenantWork). */
    public function startWork(string $key, string $name, bool $acrossTenants): void
    {
        unset($this->work[$key]);
        $this->work[$key] = [$name, $acrossTenants];
    }

    /** It has finished, whether or not it succeeded. */
    public function finishWork(string $key): void
    {
        unset($this->work[$key]);
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

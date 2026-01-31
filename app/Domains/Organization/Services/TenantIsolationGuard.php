<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Tenant Isolation Guard - Ensures cross-tenant data isolation.
 *
 * CRITICAL: One tenant's invalid data must NEVER affect another tenant.
 *
 * This service provides:
 * - Query scoping to current tenant
 * - Verification that entities belong to current tenant
 * - Audit logging of isolation violations
 * - Kill switch integration for per-tenant blast radius containment
 */
class TenantIsolationGuard
{
    /**
     * Current tenant organization ID.
     */
    private ?string $currentTenantId = null;

    /**
     * Whether isolation is enforced (should always be true in production).
     */
    private bool $enforced = true;

    /**
     * Set the current tenant context.
     */
    public function setTenant(?string $organizationId): void
    {
        $this->currentTenantId = $organizationId;

        if ($organizationId) {
            Log::debug('Tenant context set', [
                'organization_id' => $organizationId,
            ]);
        }
    }

    /**
     * Get the current tenant ID.
     */
    public function getCurrentTenantId(): ?string
    {
        return $this->currentTenantId;
    }

    /**
     * Check if tenant context is set.
     */
    public function hasTenantContext(): bool
    {
        return $this->currentTenantId !== null;
    }

    /**
     * Clear the tenant context.
     */
    public function clearTenant(): void
    {
        $this->currentTenantId = null;
    }

    /**
     * Verify an entity belongs to the current tenant.
     *
     * @throws \RuntimeException if entity doesn't belong to current tenant
     */
    public function verifyOwnership(mixed $entity, ?string $tenantId = null): bool
    {
        $checkTenantId = $tenantId ?? $this->currentTenantId;

        if (!$checkTenantId) {
            Log::warning('Ownership verification without tenant context');
            return !$this->enforced;
        }

        $entityTenantId = $this->getEntityTenantId($entity);

        if ($entityTenantId === null) {
            Log::warning('Entity has no tenant association', [
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id ?? 'unknown',
            ]);
            return !$this->enforced;
        }

        if ($entityTenantId !== $checkTenantId) {
            $this->logIsolationViolation($entity, $checkTenantId, $entityTenantId);

            if ($this->enforced) {
                throw new \RuntimeException(
                    'Tenant isolation violation: entity does not belong to current tenant'
                );
            }

            return false;
        }

        return true;
    }

    /**
     * Apply tenant scope to a query builder.
     */
    public function scopeQuery(Builder $query, ?string $tenantId = null): Builder
    {
        $checkTenantId = $tenantId ?? $this->currentTenantId;

        if (!$checkTenantId) {
            if ($this->enforced) {
                throw new \RuntimeException('Cannot execute query without tenant context');
            }
            return $query;
        }

        return $query->where('organization_id', $checkTenantId);
    }

    /**
     * Run a callback within a specific tenant context.
     *
     * @template T
     * @param string $tenantId
     * @param callable(): T $callback
     * @return T
     */
    public function runAs(string $tenantId, callable $callback): mixed
    {
        $previousTenant = $this->currentTenantId;
        $this->setTenant($tenantId);

        try {
            return $callback();
        } finally {
            $this->setTenant($previousTenant);
        }
    }

    /**
     * Run a callback without tenant context (admin operations).
     * USE WITH EXTREME CAUTION - only for system-level operations.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function runWithoutTenant(callable $callback): mixed
    {
        $previousTenant = $this->currentTenantId;
        $previousEnforced = $this->enforced;

        $this->currentTenantId = null;
        $this->enforced = false;

        try {
            Log::warning('Running operation without tenant isolation');
            return $callback();
        } finally {
            $this->currentTenantId = $previousTenant;
            $this->enforced = $previousEnforced;
        }
    }

    /**
     * Verify multiple entities belong to current tenant.
     *
     * @param iterable $entities
     * @return array{valid: bool, invalid_count: int, invalid_ids: array}
     */
    public function verifyBulkOwnership(iterable $entities): array
    {
        $invalidIds = [];

        foreach ($entities as $entity) {
            try {
                if (!$this->verifyOwnership($entity)) {
                    $invalidIds[] = $entity->id ?? 'unknown';
                }
            } catch (\RuntimeException $e) {
                $invalidIds[] = $entity->id ?? 'unknown';
            }
        }

        return [
            'valid' => empty($invalidIds),
            'invalid_count' => count($invalidIds),
            'invalid_ids' => $invalidIds,
        ];
    }

    /**
     * Get tenant ID from an entity.
     */
    private function getEntityTenantId(mixed $entity): ?string
    {
        if ($entity instanceof Organization) {
            return $entity->id;
        }

        if (property_exists($entity, 'organization_id')) {
            return $entity->organization_id;
        }

        if (method_exists($entity, 'getOrganizationId')) {
            return $entity->getOrganizationId();
        }

        // Check for organization relationship
        if (method_exists($entity, 'organization')) {
            $org = $entity->organization;
            return $org?->id;
        }

        return null;
    }

    /**
     * Log a tenant isolation violation.
     */
    private function logIsolationViolation(
        mixed $entity,
        string $expectedTenant,
        string $actualTenant
    ): void {
        Log::critical('TENANT ISOLATION VIOLATION', [
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id ?? 'unknown',
            'expected_tenant' => $expectedTenant,
            'actual_tenant' => $actualTenant,
            'request_id' => request()?->header('X-Request-ID'),
            'user_id' => auth()->id(),
        ]);

        // Here you would also dispatch to your alerting system
        // e.g., PagerDuty, Slack, etc.
    }

    /**
     * Create a tenant-scoped query for a model.
     */
    public function query(string $modelClass): Builder
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class does not exist: {$modelClass}");
        }

        $query = $modelClass::query();

        return $this->scopeQuery($query);
    }

    /**
     * Find an entity with tenant verification.
     *
     * @throws \RuntimeException if entity not found or doesn't belong to tenant
     */
    public function findOrFail(string $modelClass, string $id): mixed
    {
        $entity = $modelClass::find($id);

        if (!$entity) {
            throw new \RuntimeException("Entity not found: {$modelClass}#{$id}");
        }

        $this->verifyOwnership($entity);

        return $entity;
    }

    /**
     * Check if enforcement is enabled.
     */
    public function isEnforced(): bool
    {
        return $this->enforced;
    }

    /**
     * Enable/disable enforcement (should only be used in tests).
     */
    public function setEnforced(bool $enforced): void
    {
        if (!$enforced) {
            Log::warning('Tenant isolation enforcement DISABLED');
        }
        $this->enforced = $enforced;
    }

    /**
     * Get isolation status for debugging.
     */
    public function getStatus(): array
    {
        return [
            'current_tenant_id' => $this->currentTenantId,
            'has_context' => $this->hasTenantContext(),
            'enforced' => $this->enforced,
        ];
    }
}

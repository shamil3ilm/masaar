<?php

namespace App\Audits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Audit logging service.
 *
 * Records compliance-relevant actions for regulatory audit trails.
 */
class AuditService
{
    /**
     * Log an action.
     */
    public function log(
        string $action,
        ?Model $entity = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'organization_id' => $this->getOrganizationId(),
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log entity creation.
     */
    public function logCreated(Model $entity, ?array $metadata = null): AuditLog
    {
        return $this->log(
            action: class_basename($entity) . '.created',
            entity: $entity,
            newValues: $entity->toArray(),
            metadata: $metadata,
        );
    }

    /**
     * Log entity update.
     */
    public function logUpdated(Model $entity, array $oldValues, ?array $metadata = null): AuditLog
    {
        return $this->log(
            action: class_basename($entity) . '.updated',
            entity: $entity,
            oldValues: $oldValues,
            newValues: $entity->toArray(),
            metadata: $metadata,
        );
    }

    /**
     * Log entity deletion.
     */
    public function logDeleted(Model $entity, ?array $metadata = null): AuditLog
    {
        return $this->log(
            action: class_basename($entity) . '.deleted',
            entity: $entity,
            oldValues: $entity->toArray(),
            metadata: $metadata,
        );
    }

    /**
     * Log ZATCA submission.
     */
    public function logZatcaSubmission(Model $invoice, bool $success, array $response): AuditLog
    {
        return $this->log(
            action: $success ? 'zatca.submission.success' : 'zatca.submission.failed',
            entity: $invoice,
            metadata: [
                'response' => $response,
            ],
        );
    }

    /**
     * Log authentication event.
     */
    public function logAuth(string $action, ?string $userId = null): AuditLog
    {
        return AuditLog::create([
            'organization_id' => null,
            'user_id' => $userId ?? auth()->id(),
            'action' => 'auth.' . $action,
            'entity_type' => 'User',
            'entity_id' => $userId ?? auth()->id(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    /**
     * Get current organization ID from tenant context.
     */
    private function getOrganizationId(): ?string
    {
        return app(\App\Domains\Organization\Services\TenantResolver::class)->getOrganizationId();
    }
}

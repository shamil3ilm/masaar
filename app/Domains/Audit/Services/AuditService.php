<?php

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Database\Eloquent\Model;

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
            'org_id' => $this->getOrganizationId(),
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
            action: class_basename($entity).'.created',
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
            action: class_basename($entity).'.updated',
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
            action: class_basename($entity).'.deleted',
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
            'org_id' => null,
            'user_id' => $userId ?? auth()->id(),
            'action' => 'auth.'.$action,
            'entity_type' => 'User',
            'entity_id' => $userId ?? auth()->id(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    /**
     * Record a security-relevant event.
     *
     * Covers what an incident reconstruction needs and ordinary entity
     * auditing does not: who signed in and who failed to, who issued or
     * revoked a credential, who onboarded a certificate, who ran a privileged
     * admin action.
     *
     * Failed attempts matter as much as successful ones — a breach that shows
     * only successes cannot be told apart from normal use.
     *
     * @param  string  $action  Dotted event name, e.g. 'api_key.revoked'
     * @param  array<string, mixed>  $metadata  Never credentials; identifiers and outcome only
     */
    public function logSecurity(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::create([
            'org_id' => app(TenantResolver::class)->getOrganizationId(),
            'user_id' => auth()->id(),
            'action' => 'security.'.$action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get current organization ID from tenant context.
     */
    private function getOrganizationId(): ?string
    {
        return app(TenantResolver::class)->getOrganizationId();
    }
}

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
     * Record something done in a named organization, whoever is the tenant
     * of the moment: an invoice issued from a queue or an integration is the
     * organization's activity as much as one issued in the portal.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logFor(string $organizationId, string $action, Model $entity, array $metadata = []): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'org_id' => $organizationId,
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->getKey(),
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
     * @param  string|null  $userId  Who acted, when it is not the authenticated user - an API
     *                               sign-in authenticates through JWT, which the default guard
     *                               does not see
     */
    public function logSecurity(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
        ?string $userId = null,
    ): AuditLog {
        return AuditLog::create([
            'org_id' => app(TenantResolver::class)->getOrganizationId(),
            'user_id' => $userId ?? auth()->id(),
            'action' => 'security.'.$action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Record what a platform admin did through the console.
     *
     * Written for every change an admin makes, by AuditAdminAction rather
     * than by each controller, so a new admin action is on the record without
     * anyone remembering to put it there.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata  Outcome, route parameters and the redacted input
     */
    public function logAdminAction(
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $oldValues,
        ?array $newValues,
        array $metadata,
    ): AuditLog {
        return AuditLog::create([
            'org_id' => null,
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Record a change to something that is not a model of its own - a
     * customization's value at one level - with who made it.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function logChange(
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $oldValues,
        ?array $newValues,
        array $metadata = [],
        ?string $userId = null,
        ?string $organizationId = null,
    ): AuditLog {
        return AuditLog::create([
            'org_id' => $organizationId ?? $this->getOrganizationId(),
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
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

<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\System\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAuditTrail
{
    protected static function bootHasAuditTrail(): void
    {
        static::created(function (Model $model): void {
            $model->logAudit('created', null, $model->getAttributes());
        });

        static::updated(function (Model $model): void {
            $original = $model->getOriginal();
            $changes = $model->getChanges();

            // Remove timestamps from tracking
            unset($changes['updated_at'], $original['updated_at']);

            if (!empty($changes)) {
                $model->logAudit('updated', $original, $changes);
            }
        });

        static::deleted(function (Model $model): void {
            $model->logAudit('deleted', $model->getOriginal(), null);
        });

        // Handle soft deletes restoration if trait is used
        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model): void {
                $model->logAudit('restored', null, $model->getAttributes());
            });
        }
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    protected function logAudit(string $event, ?array $oldValues, ?array $newValues): void
    {
        // Skip if running in console without user context (e.g., seeders)
        $user = auth()->user();

        // Get organization_id from the model if available, otherwise from user
        $organizationId = $this->organization_id ?? $user?->organization_id;

        AuditLog::create([
            'organization_id' => $organizationId,
            'user_id' => $user?->id,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'old_values' => $oldValues ? $this->filterSensitiveData($oldValues) : null,
            'new_values' => $newValues ? $this->filterSensitiveData($newValues) : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
        ]);
    }

    protected function filterSensitiveData(array $data): array
    {
        $sensitiveFields = $this->getAuditExclude();

        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '[REDACTED]';
            }
        }

        return $data;
    }

    protected function getAuditExclude(): array
    {
        $defaults = ['password', 'remember_token', 'two_factor_secret', 'api_key', 'secret_key'];

        return array_merge(
            $defaults,
            property_exists($this, 'auditExclude') ? $this->auditExclude : []
        );
    }
}

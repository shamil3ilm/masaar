<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
class ActivityLog extends Model
{
    use HasFactory;
    use BelongsToOrganization, HasUuid;

    const UPDATED_AT = null;

    protected $table = 'activity_logs';

    // Actions
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_VIEWED = 'viewed';
    public const ACTION_EXPORTED = 'exported';
    public const ACTION_IMPORTED = 'imported';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_SUBMITTED = 'submitted';
    public const ACTION_PRINTED = 'printed';
    public const ACTION_EMAILED = 'emailed';
    public const ACTION_ARCHIVED = 'archived';
    public const ACTION_RESTORED = 'restored';
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_IMPERSONATION_STARTED = 'impersonation_started';
    public const ACTION_IMPERSONATION_ENDED = 'impersonation_ended';

    public const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
        self::ACTION_DELETED,
        self::ACTION_VIEWED,
        self::ACTION_EXPORTED,
        self::ACTION_IMPORTED,
        self::ACTION_APPROVED,
        self::ACTION_REJECTED,
        self::ACTION_SUBMITTED,
        self::ACTION_PRINTED,
        self::ACTION_EMAILED,
        self::ACTION_ARCHIVED,
        self::ACTION_RESTORED,
        self::ACTION_LOGIN,
        self::ACTION_LOGOUT,
        self::ACTION_IMPERSONATION_STARTED,
        self::ACTION_IMPERSONATION_ENDED,
    ];

    // Severity levels
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_INFO,
        self::SEVERITY_WARNING,
        self::SEVERITY_ERROR,
        self::SEVERITY_CRITICAL,
    ];

    // Modules
    public const MODULE_SALES = 'sales';
    public const MODULE_INVENTORY = 'inventory';
    public const MODULE_HR = 'hr';
    public const MODULE_ACCOUNTING = 'accounting';
    public const MODULE_PURCHASE = 'purchase';
    public const MODULE_CRM = 'crm';
    public const MODULE_MANUFACTURING = 'manufacturing';
    public const MODULE_CORE = 'core';

    protected $fillable = [
        'organization_id',
        'user_id',
        'impersonated_by_id',
        'impersonation_session_id',
        'branch_id',
        'action',
        'entity_type',
        'entity_id',
        'entity_name',
        'description',
        'old_values',
        'new_values',
        'changed_fields',
        'metadata',
        'ip_address',
        'user_agent',
        'request_method',
        'request_url',
        'session_id',
        'module',
        'severity',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_fields' => 'array',
            'metadata' => 'array',
            'is_system' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function impersonatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_by_id');
    }

    // Scopes

    public function scopeForEntity($query, string $entityType, ?string $entityId = null)
    {
        $query->where('entity_type', $entityType);

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        return $query;
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeSystemOnly($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeUserOnly($query)
    {
        return $query->where('is_system', false);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    // Helpers

    public function getChanges(): array
    {
        $changes = [];

        if (!$this->old_values || !$this->new_values) {
            return $changes;
        }

        foreach ($this->new_values as $key => $newValue) {
            $oldValue = $this->old_values[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }
}

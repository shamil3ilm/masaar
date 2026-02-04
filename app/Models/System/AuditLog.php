<?php

declare(strict_types=1);

namespace App\Models\System;

use App\Models\Core\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'auditable_type',
        'auditable_id',
        'event',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'url',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeForModel($query, string $modelClass, int $modelId)
    {
        return $query->where('auditable_type', $modelClass)
            ->where('auditable_id', $modelId);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeRecent($query, int $limit = 50)
    {
        return $query->orderByDesc('created_at')->limit($limit);
    }

    // Accessors
    public function getChangesAttribute(): array
    {
        if ($this->event === 'created') {
            return $this->new_values ?? [];
        }

        if ($this->event === 'deleted') {
            return $this->old_values ?? [];
        }

        $changes = [];
        $oldValues = $this->old_values ?? [];
        $newValues = $this->new_values ?? [];

        foreach ($newValues as $key => $newValue) {
            $oldValue = $oldValues[$key] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    public function getDescriptionAttribute(): string
    {
        $modelName = class_basename($this->auditable_type);
        $userName = $this->user?->name ?? 'System';

        return match ($this->event) {
            'created' => "{$userName} created {$modelName} #{$this->auditable_id}",
            'updated' => "{$userName} updated {$modelName} #{$this->auditable_id}",
            'deleted' => "{$userName} deleted {$modelName} #{$this->auditable_id}",
            'restored' => "{$userName} restored {$modelName} #{$this->auditable_id}",
            default => "{$userName} performed {$this->event} on {$modelName} #{$this->auditable_id}",
        };
    }
}

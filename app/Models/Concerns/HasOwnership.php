<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for tracking record ownership (created_by, updated_by).
 * Useful for auditing and orphan prevention.
 */
trait HasOwnership
{
    protected static function bootHasOwnership(): void
    {
        static::creating(function ($model) {
            if (!$model->created_by && auth()->check()) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * Check if user can modify this record.
     */
    public function canBeModifiedBy(int $userId): bool
    {
        // Owner can always modify
        if ($this->created_by === $userId) {
            return true;
        }

        // Check via permissions (implement in specific models if needed)
        return false;
    }
}

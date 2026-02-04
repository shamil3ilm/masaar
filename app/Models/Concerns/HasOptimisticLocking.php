<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\ConcurrencyException;

/**
 * Trait for optimistic locking to prevent lost updates.
 *
 * Usage:
 * 1. Add 'version' column to your migration: $table->unsignedInteger('version')->default(1);
 * 2. Use this trait in your model
 * 3. When updating, include 'version' in the request and call $model->updateWithVersion($data)
 */
trait HasOptimisticLocking
{
    /**
     * Boot the trait.
     */
    protected static function bootHasOptimisticLocking(): void
    {
        static::updating(function ($model) {
            // Auto-increment version on every update
            $model->version = ($model->version ?? 0) + 1;
        });
    }

    /**
     * Update the model with version checking.
     *
     * @throws ConcurrencyException
     */
    public function updateWithVersion(array $attributes, ?int $expectedVersion = null): bool
    {
        // If no version provided, use optimistic update
        if ($expectedVersion === null) {
            return $this->update($attributes);
        }

        // Check version matches
        if ($this->version !== $expectedVersion) {
            throw new ConcurrencyException(
                'The record has been modified by another user. Please refresh and try again.',
                $this
            );
        }

        return $this->update($attributes);
    }

    /**
     * Perform a safe update with automatic retry on conflict.
     */
    public function safeUpdate(array $attributes, int $maxRetries = 3): bool
    {
        $attempts = 0;

        while ($attempts < $maxRetries) {
            try {
                // Reload to get fresh version
                $this->refresh();

                return $this->update($attributes);
            } catch (ConcurrencyException $e) {
                $attempts++;
                if ($attempts >= $maxRetries) {
                    throw $e;
                }
                // Small delay before retry
                usleep(100000 * $attempts); // 100ms, 200ms, 300ms
            }
        }

        return false;
    }

    /**
     * Initialize the version attribute.
     */
    public function initializeHasOptimisticLocking(): void
    {
        $this->fillable[] = 'version';
    }

    /**
     * Get the version column name.
     */
    public function getVersionColumn(): string
    {
        return 'version';
    }
}

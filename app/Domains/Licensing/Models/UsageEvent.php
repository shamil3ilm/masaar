<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only usage event for billing and audit.
 *
 * CRITICAL: This model is NEVER deleted. Records serve as:
 * - Billing evidence
 * - Audit trail for ZATCA compliance
 * - Usage analytics
 *
 * ZATCA Compliance Note:
 * This table tracks API usage, NOT invoice data.
 * Invoice immutability is maintained separately.
 */
class UsageEvent extends Model
{
    use HasUuids;

    protected $table = 'usage_events';

    /**
     * Disable timestamps - we use occurred_at instead.
     */
    public $timestamps = false;

    /**
     * Mass assignment is intentionally limited.
     * Use the record() method for creating events.
     */
    protected $fillable = [
        'license_id',
        'organization_id',
        'api_key_id',
        'event',
        'event_category',
        'quantity',
        'billable',
        'request_id',
        'ip_address',
        'user_agent',
        'resource_id',
        'resource_type',
        'metadata',
        'occurred_at',
        'duration_ms',
        'status',
        'error_code',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'billable' => 'boolean',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    /**
     * Boot method - prevent deletion and updates.
     *
     * COMPLIANCE: Usage events are immutable once created.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Prevent any deletion
        static::deleting(function ($model) {
            throw new \RuntimeException(
                'UsageEvent records cannot be deleted. This is a compliance requirement.'
            );
        });

        // Prevent any updates - events are immutable
        static::updating(function ($model) {
            throw new \RuntimeException(
                'UsageEvent records cannot be modified. This is a compliance requirement.'
            );
        });
    }

    /**
     * Record a usage event.
     *
     * This is the primary way to create usage events.
     */
    public static function record(
        string $licenseId,
        string $event,
        array $options = []
    ): self {
        return self::create([
            'license_id' => $licenseId,
            'organization_id' => $options['organization_id'] ?? null,
            'api_key_id' => $options['api_key_id'] ?? null,
            'event' => $event,
            'event_category' => $options['event_category'] ?? self::categorizeEvent($event),
            'quantity' => $options['quantity'] ?? 1,
            'billable' => $options['billable'] ?? true,
            'request_id' => $options['request_id'] ?? request()->header('X-Request-ID'),
            'ip_address' => $options['ip_address'] ?? request()->ip(),
            'user_agent' => $options['user_agent'] ?? request()->userAgent(),
            'resource_id' => $options['resource_id'] ?? null,
            'resource_type' => $options['resource_type'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'occurred_at' => $options['occurred_at'] ?? now(),
            'duration_ms' => $options['duration_ms'] ?? null,
            'status' => $options['status'] ?? 'success',
            'error_code' => $options['error_code'] ?? null,
        ]);
    }

    /**
     * Categorize an event based on its name.
     */
    private static function categorizeEvent(string $event): string
    {
        $parts = explode('.', $event);
        return $parts[0] ?? 'api';
    }

    /**
     * Scope for billable events only.
     */
    public function scopeBillable($query)
    {
        return $query->where('billable', true);
    }

    /**
     * Scope for a specific period.
     */
    public function scopeInPeriod($query, string $period = 'month')
    {
        $start = match ($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        return $query->where('occurred_at', '>=', $start);
    }

    /**
     * Scope for successful events.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope for failed events.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // Relationships

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}

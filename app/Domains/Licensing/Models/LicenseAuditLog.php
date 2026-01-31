<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log for license changes.
 *
 * COMPLIANCE: This model is append-only.
 * Records cannot be deleted or modified - they serve as legal audit evidence.
 */
class LicenseAuditLog extends Model
{
    use HasUuids;

    protected $table = 'license_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'license_id',
        'event',
        'actor_type',
        'actor_id',
        'ip_address',
        'old_values',
        'new_values',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Boot method - prevent deletion and updates.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Prevent deletion - audit logs must be immutable
        static::deleting(function ($model) {
            throw new \RuntimeException(
                'LicenseAuditLog records cannot be deleted. This is a compliance requirement.'
            );
        });

        // Prevent updates - audit logs are append-only
        static::updating(function ($model) {
            throw new \RuntimeException(
                'LicenseAuditLog records cannot be modified. This is a compliance requirement.'
            );
        });
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}

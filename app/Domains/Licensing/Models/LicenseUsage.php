<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily usage tracking for a license.
 *
 * COMPLIANCE: Usage records cannot be deleted.
 * They serve as billing evidence and audit trail.
 */
class LicenseUsage extends Model
{
    use HasUuids;

    protected $table = 'license_usage';

    protected $fillable = [
        'license_id',
        'usage_date',
        'usage_month',
        'invoices_submitted',
        'invoices_cleared',
        'invoices_reported',
        'invoices_failed',
        'api_calls',
        'api_errors',
        'organizations_active',
        'users_active',
        'invoice_total_value',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'invoices_submitted' => 'integer',
        'invoices_cleared' => 'integer',
        'invoices_reported' => 'integer',
        'invoices_failed' => 'integer',
        'api_calls' => 'integer',
        'api_errors' => 'integer',
        'organizations_active' => 'integer',
        'users_active' => 'integer',
        'invoice_total_value' => 'decimal:2',
    ];

    /**
     * Boot method - prevent deletion.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Prevent deletion - usage records are billing/audit evidence
        static::deleting(function ($model) {
            throw new \RuntimeException(
                'LicenseUsage records cannot be deleted. This is a compliance requirement.'
            );
        });
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}

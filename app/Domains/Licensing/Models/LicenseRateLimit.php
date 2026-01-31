<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rate limit tracking for licenses (DB fallback when Redis unavailable).
 */
class LicenseRateLimit extends Model
{
    use HasUuids;

    protected $table = 'license_rate_limits';

    public $timestamps = false;

    protected $fillable = [
        'license_id',
        'window_type',
        'window_key',
        'request_count',
        'window_start',
        'window_expires',
    ];

    protected $casts = [
        'request_count' => 'integer',
        'window_start' => 'datetime',
        'window_expires' => 'datetime',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}

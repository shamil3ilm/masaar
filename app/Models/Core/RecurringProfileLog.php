<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RecurringProfileLog extends Model
{
    protected $fillable = [
        'recurring_profile_id',
        'created_type',
        'created_id',
        'scheduled_date',
        'created_date',
        'status',
        'error_message',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'created_date' => 'date',
    ];

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    // Relationships

    public function profile(): BelongsTo
    {
        return $this->belongsTo(RecurringProfile::class, 'recurring_profile_id');
    }

    public function created(): MorphTo
    {
        return $this->morphTo('created', 'created_type', 'created_id');
    }

    // Scopes

    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    // Helpers

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Webhook delivery log.
 *
 * Tracks all webhook delivery attempts for debugging and monitoring.
 */
class WebhookLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'webhook_id',
        'event',
        'payload',
        'response_status',
        'response_body',
        'duration_ms',
        'success',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'success' => 'boolean',
        ];
    }

    /**
     * Webhook that this log belongs to.
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}

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

    /**
     * The event data, as the receiver's handler saw it.
     *
     * The payload column holds the whole envelope WebhookService posted - its
     * delivery id, the event name, a timestamp, and the event data nested under
     * `data` - and not the data on its own. That matters when a delivery is
     * replayed: handing the stored envelope back to deliver() would wrap it a
     * second time, and the invoice would arrive at data.data, two levels down
     * from where any customer's handler looks. A replay has to look like the
     * delivery it repeats, carrying its own fresh id and timestamp.
     *
     * Falls back to the whole column, so a row written by anything that stored
     * a bare payload still replays as itself rather than as nothing.
     *
     * @return array<string, mixed>
     */
    public function sentPayload(): array
    {
        $stored = $this->payload ?? [];

        return is_array($stored['data'] ?? null) ? $stored['data'] : $stored;
    }
}

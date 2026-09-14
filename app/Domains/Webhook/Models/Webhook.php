<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Webhook subscription model.
 *
 * Stores webhook endpoints for organizations to receive
 * async notifications about invoice status changes.
 */
class Webhook extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'org_id',
        'url',
        'secret',
        'events',
        'is_active',
        'last_triggered_at',
        'failure_count',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    /**
     * Organization that owns this webhook.
     */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Webhook delivery logs.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    /**
     * Check if webhook is subscribed to an event.
     */
    public function isSubscribedTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true) || in_array('*', $this->events ?? [], true);
    }

    /**
     * Apply subscription changes.
     *
     * Switching the endpoint back on clears its failure count, or the next
     * failed delivery would disable it again at once. Only a literal true
     * counts as switching it on.
     *
     * @param  array{url?: string, events?: list<string>, is_active?: mixed}  $changes
     */
    public function revise(array $changes): void
    {
        $reactivated = ($changes['is_active'] ?? null) === true;

        $this->update([
            ...$changes,
            ...($reactivated ? ['failure_count' => 0] : []),
        ]);
    }

    /**
     * Replace the signing secret and return the new one.
     *
     * Receivers verify deliveries with it, so the value is returned once and
     * never read back.
     */
    public function rotateSecret(): string
    {
        $secret = Str::random(64);

        $this->update(['secret' => $secret]);

        return $secret;
    }

    /**
     * Record a successful delivery.
     */
    public function recordSuccess(): void
    {
        $this->update([
            'last_triggered_at' => now(),
            'failure_count' => 0,
        ]);
    }

    /**
     * Record a failed delivery.
     */
    public function recordFailure(): void
    {
        $this->increment('failure_count');

        // Disable after 10 consecutive failures
        if ($this->failure_count >= 10) {
            $this->update(['is_active' => false]);
        }
    }
}

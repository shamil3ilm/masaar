<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    use RecordsActors;
    use SoftDeletes;

    public const FAILURES_BEFORE_DISABLE = 10;

    public const SILENT_HOURS_BEFORE_DISABLE = 24;

    /**
     * How long after a rotation deliveries are signed with the old secret
     * as well as the new: a day for a receiver to deploy the new one.
     */
    public const ROTATION_OVERLAP_HOURS = 24;

    protected $fillable = [
        'org_id',
        'url',
        'secret',
        'previous_secret',
        'previous_secret_expires_at',
        'events',
        'is_active',
        'last_triggered_at',
        'failure_count',
    ];

    /**
     * The signing secret is shown once, when it is made, and never again.
     */
    protected $hidden = [
        'secret',
        'previous_secret',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted at rest: a copy of the database must not be enough
            // to forge a delivery a receiver trusts.
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
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
     * never read back. The secret replaced goes on signing beside it for
     * ROTATION_OVERLAP_HOURS, so a receiver holding either verifies, unless
     * it is revoked now - a secret that leaked. Only the one just replaced
     * is kept: rotating again ends the one before.
     */
    public function rotateSecret(bool $revokePrevious = false): string
    {
        $secret = Str::random(64);

        $this->update([
            'secret' => $secret,
            'previous_secret' => $revokePrevious ? null : $this->secret,
            'previous_secret_expires_at' => $revokePrevious ? null : now()->addHours(self::ROTATION_OVERLAP_HOURS),
        ]);

        return $secret;
    }

    /**
     * The secrets a delivery is signed with now: the current one, and the
     * one a rotation replaced while its overlap lasts.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $overlapping = $this->previous_secret !== null
            && $this->previous_secret_expires_at !== null
            && $this->previous_secret_expires_at->isFuture();

        return $overlapping ? [$this->secret, $this->previous_secret] : [$this->secret];
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

        // Ten failed deliveries in a row, and nothing delivered for a day.
        // Ten alone disabled a busy endpoint during a half-hour outage, when
        // ten invoices are an ordinary morning. last_triggered_at is written
        // only on success, so it is when the receiver last answered; one that
        // never has is measured from when it was added.
        $lastAnswered = $this->last_triggered_at ?? $this->created_at;

        if ($this->failure_count >= self::FAILURES_BEFORE_DISABLE
            && $lastAnswered !== null
            && $lastAnswered->lte(now()->subHours(self::SILENT_HOURS_BEFORE_DISABLE))) {
            $this->update(['is_active' => false]);
        }
    }
}

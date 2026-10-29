<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * An API request made once under an Idempotency-Key, and what it answered
 * (the idempotent middleware).
 *
 * A row is written before the request runs, with no answer, so a second
 * request under the key while the first runs is told so; the answer is kept
 * when the request succeeds, and the row is removed when it does not, since
 * a refused request made nothing and may be sent again. A row left with no
 * answer past IN_FLIGHT_MINUTES is a request that died (abandoned()). The answer can hold
 * a webhook's signing secret, so it is encrypted at rest.
 *
 * Not tenant-scoped: the middleware names the organization in every query.
 *
 * @property string $id
 * @property string $org_id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int|null $status
 * @property string|null $response
 */
class IdempotentRequest extends Model
{
    use HasUuids;
    use MassPrunable;

    /** How long a key is remembered. A retry comes within minutes; a month is generous. */
    public const RETENTION_DAYS = 30;

    /**
     * How long a request may go unanswered before its process is taken to
     * have died - a fatal error, the time limit - leaving the row with no
     * answer. Far past any request's time limit; well short of a month.
     */
    public const IN_FLIGHT_MINUTES = 5;

    protected $fillable = [
        'org_id',
        'idempotency_key',
        'request_hash',
        'status',
        'response',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'response' => 'encrypted',
        ];
    }

    /** No answer, and none coming: its request died mid-flight. */
    public function abandoned(): bool
    {
        return $this->status === null
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subMinutes(self::IN_FLIGHT_MINUTES));
    }

    /**
     * Take an abandoned key over for the request sent again. Of two retries
     * that both found it abandoned, one takes it; the other is told the key
     * is in use.
     */
    public function claim(): bool
    {
        return static::query()
            ->whereKey($this->id)
            ->whereNull('status')
            ->where('updated_at', $this->getRawOriginal('updated_at'))
            ->update(['updated_at' => now()]) === 1;
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}

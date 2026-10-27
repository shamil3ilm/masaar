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
 * a refused request made nothing and may be sent again. The answer can hold
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

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}

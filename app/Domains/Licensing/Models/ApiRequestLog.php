<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One partner API request, as its organization's administrators read it:
 * no body, header, query string, secret or address (RequestLog).
 *
 * @property string $id
 * @property string $org_id
 * @property string $license_id
 * @property string|null $api_key_id
 * @property string $method
 * @property string $path
 * @property int $status
 * @property string|null $error_code
 * @property string|null $request_id
 * @property int $duration_ms
 * @property Carbon $occurred_at
 */
class ApiRequestLog extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    /** Written once, at its own moment, and never changed. */
    public $timestamps = false;

    protected $fillable = [
        'org_id',
        'license_id',
        'api_key_id',
        'method',
        'path',
        'status',
        'error_code',
        'duration_ms',
        'occurred_at',
    ];

    /**
     * Only the request it came in: a log row keeps no actor. RequestLog
     * sets the id ValidateLicense answered with.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['request_id' => 'request_id'];
    }

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}

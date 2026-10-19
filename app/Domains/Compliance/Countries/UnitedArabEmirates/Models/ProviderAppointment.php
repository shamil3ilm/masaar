<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A UAE organization's appointment of its accredited service provider.
 *
 * One provider for sending and receiving (UAE Electronic Invoicing
 * Guidelines v1.1, p14), appointed by a last date (p19) through EmaraTax
 * (p38-39). A compliance record: its status moves, and it is never deleted;
 * a new provider ends the current appointment and starts another.
 */
class ProviderAppointment extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const PENDING = 'pending';

    public const APPOINTED = 'appointed';

    public const ENDED = 'ended';

    public const STATUSES = [self::PENDING, self::APPOINTED, self::ENDED];

    protected $fillable = [
        'org_id',
        'provider',
        'status',
        'appointed_at',
        'ended_at',
        'reference',
        'deadline_on',
    ];

    /**
     * created_by and updated_by: whoever records and changes it.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by'];
    }

    protected function casts(): array
    {
        return [
            'appointed_at' => 'datetime',
            'ended_at' => 'datetime',
            'deadline_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new RuntimeException('A provider appointment is a compliance record and is never deleted; end it instead.');
        });
    }
}

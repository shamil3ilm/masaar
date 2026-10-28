<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A VAT period the organization filed, with the return's figures as filed.
 * Written by VatPeriods only: filed, then perhaps reopened by an owner -
 * never deleted.
 *
 * @property string $id
 * @property string $org_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon $filed_on
 * @property array{currency: string, boxes: array<string, array{label: string, amount: float|null, adjustment: float|null, vat: float|null}>} $figures
 * @property string $status
 * @property string|null $reopen_reason
 * @property Carbon|null $reopened_at
 */
class VatPeriod extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const FILED = 'filed';

    public const REOPENED = 'reopened';

    protected $fillable = ['org_id', 'starts_on', 'ends_on', 'filed_on', 'figures'];

    /**
     * A compliance record: who filed it; reopened_by is set by VatPeriods.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'filed_on' => 'date',
            'figures' => 'array',
            'reopened_at' => 'datetime',
        ];
    }

    public function isFiled(): bool
    {
        return $this->status === self::FILED;
    }
}

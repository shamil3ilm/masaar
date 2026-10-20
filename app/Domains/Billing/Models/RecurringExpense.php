<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Organization\Concerns\RecordsActors;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A cost the platform expects every month or every year, and when its next
 * bill is due. Written by RecurringExpenses only.
 *
 * @property string $id
 * @property string $supplier_id
 * @property string $description
 * @property string $category
 * @property string|null $cost_centre
 * @property string $currency
 * @property string $net
 * @property string $vat
 * @property VatTreatment $vat_treatment
 * @property string $frequency
 * @property Carbon $next_due_on
 * @property bool $active
 * @property Carbon|null $last_drafted_on
 */
class RecurringExpense extends Model
{
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    protected $fillable = [
        'supplier_id',
        'description',
        'category',
        'cost_centre',
        'currency',
        'net',
        'vat',
        'vat_treatment',
        'frequency',
        'next_due_on',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'net' => 'decimal:2',
            'vat' => 'decimal:2',
            'vat_treatment' => VatTreatment::class,
            'next_due_on' => 'date',
            'active' => 'boolean',
            'last_drafted_on' => 'date',
        ];
    }

    /** @return array<string, string> */
    public static function frequencies(): array
    {
        return [self::MONTHLY => __('Every month'), self::YEARLY => __('Every year')];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** The bill after one due on a day: a month or a year on, the day kept where it can be. */
    public function after(CarbonImmutable $due): CarbonImmutable
    {
        return $this->frequency === self::YEARLY ? $due->addYearNoOverflow() : $due->addMonthNoOverflow();
    }
}

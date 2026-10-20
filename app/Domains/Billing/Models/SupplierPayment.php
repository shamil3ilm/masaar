<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\OutgoingChannel;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money the platform paid a supplier against one expense, with how it went
 * out and the reference its statement shows. Written by SupplierPayments
 * only; never changed.
 *
 * @property string $id
 * @property string $expense_id
 * @property string $supplier_id
 * @property Carbon $paid_on
 * @property string $currency
 * @property string $amount
 * @property string $amount_tax
 * @property OutgoingChannel $channel
 * @property string|null $reference
 * @property string|null $paid_from
 */
class SupplierPayment extends Model
{
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    protected $fillable = [
        'expense_id',
        'supplier_id',
        'paid_on',
        'currency',
        'amount',
        'amount_tax',
        'channel',
        'reference',
        'paid_from',
        'notes',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'settled_tax',
        'exchange_difference',
    ];

    /**
     * A financial record: who recorded it and how, never who changed it.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via'];
    }

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
            'amount_tax' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'rate_date' => 'date',
            'settled_tax' => 'decimal:2',
            'exchange_difference' => 'decimal:2',
            'channel' => OutgoingChannel::class,
        ];
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}

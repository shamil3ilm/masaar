<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A supplier's credit note against one expense: what it takes off the
 * bill and the VAT it takes back, at the bill's own rate. Written by
 * SupplierCredits only; never changed.
 *
 * @property string $id
 * @property string $expense_id
 * @property string $supplier_id
 * @property Carbon $credit_date
 * @property string|null $supplier_credit_number
 * @property string $currency
 * @property string $net
 * @property string $vat
 * @property string $gross
 * @property string $net_tax
 * @property string $vat_tax
 * @property string $gross_tax
 * @property string $self_assessed_vat_tax
 * @property string $recoverable_vat_tax
 * @property string $reason
 */
class SupplierCreditNote extends Model
{
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    protected $fillable = [
        'expense_id', 'supplier_id', 'credit_date', 'supplier_credit_number', 'currency', 'net', 'vat', 'gross', 'exchange_rate',
        'net_tax', 'vat_tax', 'gross_tax', 'self_assessed_vat_tax', 'recoverable_vat_tax', 'reason',
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
            'credit_date' => 'date',
            'net' => 'decimal:2',
            'vat' => 'decimal:2',
            'gross' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'net_tax' => 'decimal:2',
            'vat_tax' => 'decimal:2',
            'gross_tax' => 'decimal:2',
            'self_assessed_vat_tax' => 'decimal:2',
            'recoverable_vat_tax' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}

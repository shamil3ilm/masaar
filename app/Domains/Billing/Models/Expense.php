<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Organization\Concerns\RecordsActors;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One cost of the platform's own: a supplier's invoice or receipt, its VAT
 * and how the VAT return takes it. Written by Expenses only, which works
 * out the tax-currency figures and checks for the same invoice twice.
 *
 * The platform's own, so not tenant-scoped. Never deleted: a mistaken one
 * is void, with who and why.
 *
 * @property string $id
 * @property string $supplier_id
 * @property ExpenseStatus $status
 * @property Carbon $expense_date
 * @property Carbon $due_date
 * @property string|null $supplier_invoice_number
 * @property string $description
 * @property string $category
 * @property string|null $cost_centre
 * @property string $currency
 * @property string $net
 * @property string $vat
 * @property string $gross
 * @property string $tax_currency
 * @property string|null $exchange_rate
 * @property string $net_tax
 * @property string $vat_tax
 * @property string $gross_tax
 * @property VatTreatment $vat_treatment
 * @property string $recoverable_vat_tax
 * @property string|null $vat_rate
 * @property string $self_assessed_vat_tax
 * @property string|null $receipt_path
 * @property string|null $receipt_name
 * @property string|null $receipt_mime
 */
class Expense extends Model
{
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'supplier_id',
        'expense_date',
        'due_date',
        'supplier_invoice_number',
        'description',
        'category',
        'cost_centre',
        'currency',
        'net',
        'vat',
        'gross',
        'tax_currency',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'net_tax',
        'vat_tax',
        'gross_tax',
        'vat_treatment',
        'recoverable_vat_tax',
        'vat_rate',
        'self_assessed_vat_tax',
        'notes',
    ];

    /**
     * A financial record: who entered it, who last changed it while it was
     * still open, and how it arrived. Who voided it is its own column.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by', 'created_via' => 'created_via'];
    }

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'expense_date' => 'date',
            'due_date' => 'date',
            'net' => 'decimal:2',
            'vat' => 'decimal:2',
            'gross' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'rate_date' => 'date',
            'net_tax' => 'decimal:2',
            'vat_tax' => 'decimal:2',
            'gross_tax' => 'decimal:2',
            'vat_treatment' => VatTreatment::class,
            'recoverable_vat_tax' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'self_assessed_vat_tax' => 'decimal:2',
            'receipt_size' => 'integer',
            'voided_at' => 'datetime',
            'amount_paid' => 'decimal:2',
            'amount_written_off' => 'decimal:2',
            'paid_on' => 'date',
            'written_off_at' => 'datetime',
        ];
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderBy('paid_on');
    }

    /** Still owed to the supplier, in the expense's currency. */
    public function balance(): string
    {
        return Receivable::money(max(0.0, (float) $this->gross - (float) $this->amount_paid - (float) $this->amount_written_off));
    }

    /** Whole days past the due date on a day; 0 or less when not yet due. */
    public function daysOverdue(CarbonImmutable $on): int
    {
        return (int) CarbonImmutable::parse($this->due_date->toDateString())->diffInDays($on->startOfDay(), false);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** Recorded and still owed to its supplier. */
    public function owed(): bool
    {
        return in_array($this->status->value, ExpenseStatus::owedValues(), true);
    }

    /** What the reports count: recorded, not a draft and not void. */
    public function counted(): bool
    {
        return $this->status->counted();
    }

    /** Still changeable: nothing paid against it, not void. */
    public function editable(): bool
    {
        return in_array($this->status, [ExpenseStatus::Draft, ExpenseStatus::Unpaid], true);
    }
}

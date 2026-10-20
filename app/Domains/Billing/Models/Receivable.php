<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One invoice the platform issued a customer, and what is still owed on it.
 *
 * Its status follows from its sums and its due date (status()); whatever
 * changes a sum saves the status with it, and a daily run moves the ones
 * that fell due (Receivables::refresh).
 */
class Receivable extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'org_id',
        'invoice_id',
        'license_id',
        'description',
        'period_start',
        'period_end',
        'currency',
        'subtotal',
        'vat_amount',
        'total',
        'tax_currency',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'vat_amount_tax',
        'issue_date',
        'due_date',
        'status',
        'amount_paid',
        'amount_credited',
        'amount_written_off',
        'paid_on',
    ];

    /**
     * A financial record: who issued it and how, never who changed it
     * after; who wrote it off is its own column. license_id is the plan it
     * bills, not a channel's licence.
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
            'period_start' => 'date',
            'period_end' => 'date',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'rate_date' => 'date',
            'vat_amount_tax' => 'decimal:2',
            'issue_date' => 'date',
            'due_date' => 'date',
            'status' => ReceivableStatus::class,
            'amount_paid' => 'decimal:2',
            'amount_credited' => 'decimal:2',
            'amount_written_off' => 'decimal:2',
            'paid_on' => 'date',
            'written_off_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * The tax invoice. It belongs to the billing organization, not to the
     * customer, so it is read without the tenant scope.
     */
    public function invoice(): ?Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->find($this->invoice_id));
    }

    /** What is still owed, as "120.00". */
    public function balance(): string
    {
        return self::money((float) $this->total - (float) $this->amount_paid - (float) $this->amount_credited - (float) $this->amount_written_off);
    }

    /** Whole days past the due date on a day; 0 or less when not yet due. */
    public function daysOverdue(CarbonImmutable $on): int
    {
        return (int) CarbonImmutable::parse($this->due_date->toDateString())->diffInDays($on->startOfDay(), false);
    }

    /**
     * What its sums and due date make it on a day. Written off once any of
     * it is and nothing is left; paid once nothing is left; overdue once
     * past due with something left, part paid or not.
     */
    public function statusOn(CarbonImmutable $on): ReceivableStatus
    {
        $left = (float) $this->balance();

        if ($left <= 0.0) {
            return (float) $this->amount_written_off > 0 ? ReceivableStatus::WrittenOff : ReceivableStatus::Paid;
        }

        if ($this->daysOverdue($on) > 0) {
            return ReceivableStatus::Overdue;
        }

        return (float) $this->amount_paid > 0 || (float) $this->amount_credited > 0 ? ReceivableStatus::PartlyPaid : ReceivableStatus::Open;
    }

    public static function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}

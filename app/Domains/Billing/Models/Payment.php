<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\PaymentChannel;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money a customer paid the platform: for a plan, recorded by a platform
 * admin with the plan, months and period it buys; or against its invoices,
 * by bank transfer or by card, set against them by allocations.
 */
class Payment extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'org_id',
        'license_id',
        'tier',
        'months',
        'period_start',
        'period_end',
        'amount',
        'vat_amount',
        'total',
        'currency',
        'method',
        'reference',
        'paid_at',
        'notes',
        'recorded_by',
        'invoice_id',
        'invoice_error',
        'channel',
        'bank_account',
        'gateway_reference',
        'fee_amount',
        'exchange_rate',
    ];

    /**
     * A financial record: who recorded it and how, never who changed it
     * after. Its license_id is the licence it pays for, so the channel's
     * licence is not stamped on it.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'recorded_by', 'created_via' => 'created_via'];
    }

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'method' => PaymentMethod::class,
            'paid_at' => 'date',
            'channel' => PaymentChannel::class,
            'fee_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
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
     * The platform's invoice for it. It belongs to the billing organization,
     * not to the one that paid, so it is read without the tenant scope.
     */
    public function invoice(): ?Invoice
    {
        return $this->invoice_id === null
            ? null
            : Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->find($this->invoice_id));
    }
}

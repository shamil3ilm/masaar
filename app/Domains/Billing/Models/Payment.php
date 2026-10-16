<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment for a plan, recorded by a platform admin.
 */
class Payment extends Model
{
    use BelongsToTenant;
    use HasUuids;

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
    ];

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
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
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

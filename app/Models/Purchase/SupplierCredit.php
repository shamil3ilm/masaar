<?php

declare(strict_types=1);

namespace App\Models\Purchase;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\Sales\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCredit extends Model
{
    use BelongsToOrganization, HasUuid;

    public const SOURCE_ADVANCE_PAYMENT = 'advance_payment';
    public const SOURCE_CREDIT_NOTE = 'credit_note';
    public const SOURCE_OVERPAYMENT = 'overpayment';
    public const SOURCE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'organization_id',
        'supplier_id',
        'source_type',
        'source_id',
        'original_amount',
        'remaining_amount',
        'currency_code',
        'credit_date',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:4',
            'remaining_amount' => 'decimal:4',
            'credit_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function applyToBill(Bill $bill, float $amount): float
    {
        $amountToApply = min($amount, (float) $this->remaining_amount, (float) $bill->amount_due);

        if ($amountToApply <= 0) {
            return 0;
        }

        $this->remaining_amount = bcsub((string) $this->remaining_amount, (string) $amountToApply, 4);

        if (bccomp((string) $this->remaining_amount, '0', 4) <= 0) {
            $this->is_active = false;
        }

        $this->save();

        $bill->recordPayment($amountToApply);

        return (float) $amountToApply;
    }

    public function hasBalance(): bool
    {
        return bccomp((string) $this->remaining_amount, '0', 4) > 0;
    }

    public function getUsedAmount(): float
    {
        return (float) bcsub((string) $this->original_amount, (string) $this->remaining_amount, 4);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('remaining_amount', '>', 0);
    }

    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }
}

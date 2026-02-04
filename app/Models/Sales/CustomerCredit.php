<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCredit extends Model
{
    use BelongsToOrganization, HasUuid;

    public const SOURCE_ADVANCE_PAYMENT = 'advance_payment';
    public const SOURCE_CREDIT_NOTE = 'credit_note';
    public const SOURCE_OVERPAYMENT = 'overpayment';
    public const SOURCE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'organization_id',
        'customer_id',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * Apply credit to an invoice.
     */
    public function applyToInvoice(Invoice $invoice, float $amount): float
    {
        $amountToApply = min($amount, (float) $this->remaining_amount, (float) $invoice->amount_due);

        if ($amountToApply <= 0) {
            return 0;
        }

        $this->remaining_amount = bcsub((string) $this->remaining_amount, (string) $amountToApply, 4);

        if (bccomp((string) $this->remaining_amount, '0', 4) <= 0) {
            $this->is_active = false;
        }

        $this->save();

        $invoice->recordPayment($amountToApply);

        return (float) $amountToApply;
    }

    /**
     * Check if credit has remaining balance.
     */
    public function hasBalance(): bool
    {
        return bccomp((string) $this->remaining_amount, '0', 4) > 0;
    }

    /**
     * Get used amount.
     */
    public function getUsedAmount(): float
    {
        return (float) bcsub((string) $this->original_amount, (string) $this->remaining_amount, 4);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('remaining_amount', '>', 0);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeBySource($query, string $sourceType)
    {
        return $query->where('source_type', $sourceType);
    }
}

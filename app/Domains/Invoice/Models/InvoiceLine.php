<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoice line item.
 *
 * Represents a single product/service on an invoice.
 */
class InvoiceLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'invoice_id',
        'description',
        'item_classification_code',
        'quantity',
        'unit_code',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'tax_category',
        'tax_exemption_code',
        'tax_exemption_reason',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * Parent invoice.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Calculate line total (quantity × unit_price).
     */
    public function calculateSubtotal(): string
    {
        return bcmul($this->quantity, $this->unit_price, 2);
    }

    /**
     * Calculate tax amount based on tax rate.
     */
    public function calculateTax(): string
    {
        $subtotal = $this->calculateSubtotal();
        return bcmul($subtotal, bcdiv($this->tax_rate, '100', 4), 2);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\UnitOfMeasure;
use App\Models\Inventory\Warehouse;
use App\Models\Tax\TaxCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'variant_id',
        'description',
        'quantity',
        'unit_id',
        'unit_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_category_id',
        'tax_rate',
        'tax_amount',
        'tax_code',
        'cgst_rate',
        'cgst_amount',
        'sgst_rate',
        'sgst_amount',
        'igst_rate',
        'igst_amount',
        'hsn_code',
        'subtotal',
        'total',
        'account_id',
        'warehouse_id',
        'line_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'cgst_rate' => 'decimal:4',
            'cgst_amount' => 'decimal:4',
            'sgst_rate' => 'decimal:4',
            'sgst_amount' => 'decimal:4',
            'igst_rate' => 'decimal:4',
            'igst_amount' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'total' => 'decimal:4',
            'line_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (InvoiceLine $line) {
            $line->calculateTotals();
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Calculate line totals.
     */
    public function calculateTotals(): void
    {
        // Gross amount
        $gross = bcmul((string) $this->quantity, (string) $this->unit_price, 4);

        // Apply discount
        if ($this->discount_type === 'percentage' && $this->discount_value > 0) {
            $this->discount_amount = bcmul($gross, bcdiv((string) $this->discount_value, '100', 6), 4);
        } elseif ($this->discount_type === 'fixed') {
            $this->discount_amount = $this->discount_value;
        } else {
            $this->discount_amount = 0;
        }

        // Subtotal (before tax)
        $this->subtotal = bcsub($gross, (string) $this->discount_amount, 4);

        // Tax calculation
        if ($this->tax_rate > 0) {
            $this->tax_amount = bcmul((string) $this->subtotal, bcdiv((string) $this->tax_rate, '100', 6), 4);
        } else {
            $this->tax_amount = 0;
        }

        // Handle GST split (India)
        $this->calculateGstSplit();

        // Total (after tax)
        $this->total = bcadd((string) $this->subtotal, (string) $this->tax_amount, 4);
    }

    /**
     * Calculate GST split for India.
     */
    protected function calculateGstSplit(): void
    {
        // If IGST is set, it's inter-state
        if ($this->igst_rate > 0) {
            $this->igst_amount = bcmul((string) $this->subtotal, bcdiv((string) $this->igst_rate, '100', 6), 4);
            $this->cgst_amount = 0;
            $this->sgst_amount = 0;
            $this->tax_amount = $this->igst_amount;
        } elseif ($this->cgst_rate > 0 || $this->sgst_rate > 0) {
            // Intra-state: CGST + SGST
            $this->cgst_amount = bcmul((string) $this->subtotal, bcdiv((string) $this->cgst_rate, '100', 6), 4);
            $this->sgst_amount = bcmul((string) $this->subtotal, bcdiv((string) $this->sgst_rate, '100', 6), 4);
            $this->igst_amount = 0;
            $this->tax_amount = bcadd((string) $this->cgst_amount, (string) $this->sgst_amount, 4);
        }
    }

    /**
     * Get tax breakdown.
     */
    public function getTaxBreakdown(): array
    {
        if ($this->igst_amount > 0) {
            return [
                'type' => 'igst',
                'igst' => ['rate' => $this->igst_rate, 'amount' => $this->igst_amount],
            ];
        }

        if ($this->cgst_amount > 0 || $this->sgst_amount > 0) {
            return [
                'type' => 'cgst_sgst',
                'cgst' => ['rate' => $this->cgst_rate, 'amount' => $this->cgst_amount],
                'sgst' => ['rate' => $this->sgst_rate, 'amount' => $this->sgst_amount],
            ];
        }

        return [
            'type' => 'vat',
            'vat' => ['rate' => $this->tax_rate, 'amount' => $this->tax_amount],
        ];
    }
}

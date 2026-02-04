<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\UnitOfMeasure;
use App\Models\Inventory\Warehouse;
use App\Models\Tax\TaxCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    protected $fillable = [
        'sales_order_id',
        'product_id',
        'variant_id',
        'description',
        'quantity',
        'quantity_delivered',
        'quantity_invoiced',
        'unit_id',
        'unit_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_category_id',
        'tax_rate',
        'tax_amount',
        'subtotal',
        'total',
        'warehouse_id',
        'line_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'quantity_invoiced' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'total' => 'decimal:4',
            'line_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SalesOrderLine $line) {
            $line->calculateTotals();
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Calculate line totals.
     */
    public function calculateTotals(): void
    {
        $gross = bcmul((string) $this->quantity, (string) $this->unit_price, 4);

        if ($this->discount_type === 'percentage' && $this->discount_value > 0) {
            $this->discount_amount = bcmul($gross, bcdiv((string) $this->discount_value, '100', 6), 4);
        } elseif ($this->discount_type === 'fixed') {
            $this->discount_amount = $this->discount_value;
        } else {
            $this->discount_amount = 0;
        }

        $this->subtotal = bcsub($gross, (string) $this->discount_amount, 4);

        if ($this->tax_rate > 0) {
            $this->tax_amount = bcmul((string) $this->subtotal, bcdiv((string) $this->tax_rate, '100', 6), 4);
        } else {
            $this->tax_amount = 0;
        }

        $this->total = bcadd((string) $this->subtotal, (string) $this->tax_amount, 4);
    }

    /**
     * Get remaining quantity to deliver.
     */
    public function getRemainingToDeliver(): float
    {
        return max(0, (float) bcsub((string) $this->quantity, (string) $this->quantity_delivered, 4));
    }

    /**
     * Get remaining quantity to invoice.
     */
    public function getRemainingToInvoice(): float
    {
        return max(0, (float) bcsub((string) $this->quantity_delivered, (string) $this->quantity_invoiced, 4));
    }

    /**
     * Check if fully delivered.
     */
    public function isFullyDelivered(): bool
    {
        return bccomp((string) $this->quantity_delivered, (string) $this->quantity, 4) >= 0;
    }

    /**
     * Check if fully invoiced.
     */
    public function isFullyInvoiced(): bool
    {
        return bccomp((string) $this->quantity_invoiced, (string) $this->quantity, 4) >= 0;
    }
}

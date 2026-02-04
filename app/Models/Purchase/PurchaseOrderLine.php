<?php

declare(strict_types=1);

namespace App\Models\Purchase;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\UnitOfMeasure;
use App\Models\Inventory\Warehouse;
use App\Models\Tax\TaxCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'variant_id',
        'description',
        'quantity',
        'quantity_received',
        'quantity_billed',
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
            'quantity_received' => 'decimal:4',
            'quantity_billed' => 'decimal:4',
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
        static::saving(function (PurchaseOrderLine $line) {
            $line->calculateTotals();
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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

    public function getRemainingToReceive(): float
    {
        return max(0, (float) bcsub((string) $this->quantity, (string) $this->quantity_received, 4));
    }

    public function getRemainingToBill(): float
    {
        return max(0, (float) bcsub((string) $this->quantity_received, (string) $this->quantity_billed, 4));
    }

    public function isFullyReceived(): bool
    {
        return bccomp((string) $this->quantity_received, (string) $this->quantity, 4) >= 0;
    }

    public function isFullyBilled(): bool
    {
        return bccomp((string) $this->quantity_billed, (string) $this->quantity, 4) >= 0;
    }
}

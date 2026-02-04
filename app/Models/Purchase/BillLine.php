<?php

declare(strict_types=1);

namespace App\Models\Purchase;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\UnitOfMeasure;
use App\Models\Inventory\Warehouse;
use App\Models\Tax\TaxCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillLine extends Model
{
    protected $fillable = [
        'bill_id',
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
        static::saving(function (BillLine $line) {
            $line->calculateTotals();
        });
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
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

        $this->calculateGstSplit();

        $this->total = bcadd((string) $this->subtotal, (string) $this->tax_amount, 4);
    }

    protected function calculateGstSplit(): void
    {
        if ($this->igst_rate > 0) {
            $this->igst_amount = bcmul((string) $this->subtotal, bcdiv((string) $this->igst_rate, '100', 6), 4);
            $this->cgst_amount = 0;
            $this->sgst_amount = 0;
            $this->tax_amount = $this->igst_amount;
        } elseif ($this->cgst_rate > 0 || $this->sgst_rate > 0) {
            $this->cgst_amount = bcmul((string) $this->subtotal, bcdiv((string) $this->cgst_rate, '100', 6), 4);
            $this->sgst_amount = bcmul((string) $this->subtotal, bcdiv((string) $this->sgst_rate, '100', 6), 4);
            $this->igst_amount = 0;
            $this->tax_amount = bcadd((string) $this->cgst_amount, (string) $this->sgst_amount, 4);
        }
    }
}

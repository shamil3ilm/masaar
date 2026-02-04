<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    protected $fillable = [
        'price_list_id',
        'product_id',
        'unit_price',
        'min_quantity',
        'max_quantity',
        'discount_percent',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'unit_price' => 'decimal:4',
        'min_quantity' => 'decimal:4',
        'max_quantity' => 'decimal:4',
        'discount_percent' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    // Relationships

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Scopes

    public function scopeValid($query, $date = null)
    {
        $date = $date ?? today();

        return $query->where(function ($q) use ($date) {
            $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date);
        });
    }

    public function scopeForQuantity($query, float $quantity)
    {
        return $query->where('min_quantity', '<=', $quantity)
            ->where(function ($q) use ($quantity) {
                $q->whereNull('max_quantity')
                    ->orWhere('max_quantity', '>=', $quantity);
            });
    }

    // Helpers

    public function isValid(): bool
    {
        $today = today();

        if ($this->valid_from && $this->valid_from->gt($today)) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->lt($today)) {
            return false;
        }

        return true;
    }

    public function getEffectivePrice(): string
    {
        if ($this->discount_percent > 0) {
            $discount = bcmul($this->unit_price, bcdiv((string) $this->discount_percent, '100', 6), 4);
            return bcsub($this->unit_price, $discount, 4);
        }

        return $this->unit_price;
    }

    public function isBulkTier(): bool
    {
        return $this->min_quantity > 1;
    }
}

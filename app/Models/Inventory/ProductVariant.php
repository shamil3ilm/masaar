<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'attribute_values',
        'purchase_price',
        'selling_price',
        'cost_price',
        'barcode',
        'weight',
        'dimensions',
        'image_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'attribute_values' => 'array',
            'dimensions' => 'array',
            'purchase_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'cost_price' => 'decimal:4',
            'weight' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'variant_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    /**
     * Get the effective purchase price (variant or product fallback).
     */
    public function getEffectivePurchasePrice(): float
    {
        return $this->purchase_price ?? $this->product->purchase_price ?? 0;
    }

    /**
     * Get the effective selling price (variant or product fallback).
     */
    public function getEffectiveSellingPrice(): float
    {
        return $this->selling_price ?? $this->product->selling_price ?? 0;
    }

    /**
     * Get total stock across all warehouses.
     */
    public function getTotalStock(): float
    {
        return $this->stockLevels()->sum('quantity');
    }

    /**
     * Get available stock across all warehouses.
     */
    public function getAvailableStock(): float
    {
        return $this->stockLevels()
            ->selectRaw('SUM(quantity - reserved_quantity) as available')
            ->value('available') ?? 0;
    }

    /**
     * Get formatted variant name with attributes.
     */
    public function getFullName(): string
    {
        if (empty($this->attribute_values)) {
            return $this->name ?? $this->product->name;
        }

        $attributes = collect($this->attribute_values)
            ->map(fn($value, $key) => "{$key}: {$value}")
            ->implode(', ');

        return "{$this->product->name} ({$attributes})";
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithAttribute($query, string $attribute, $value)
    {
        return $query->whereJsonContains("attribute_values->{$attribute}", $value);
    }
}

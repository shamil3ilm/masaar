<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Accounting\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAuditTrail;
use App\Models\Concerns\HasOwnership;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUuid;
    use BelongsToOrganization;
    use HasAuditTrail;
    use HasOwnership;
    use SoftDeletes;

    public const TYPE_GOODS = 'goods';
    public const TYPE_SERVICE = 'service';

    public const COSTING_FIFO = 'fifo';
    public const COSTING_WEIGHTED_AVERAGE = 'weighted_average';
    public const COSTING_STANDARD = 'standard';

    protected $fillable = [
        'organization_id',
        'sku',
        'barcode',
        'name',
        'description',
        'type',
        'category_id',
        'unit_id',
        'purchase_price',
        'selling_price',
        'minimum_price',
        'tax_category_id',
        'hsn_code',
        'income_account_id',
        'expense_account_id',
        'inventory_account_id',
        'costing_method',
        'track_inventory',
        'reorder_level',
        'reorder_quantity',
        'weight',
        'weight_unit',
        'length',
        'width',
        'height',
        'dimension_unit',
        'image_url',
        'gallery_urls',
        'is_active',
        'is_purchasable',
        'is_sellable',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'minimum_price' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'reorder_quantity' => 'decimal:4',
            'weight' => 'decimal:3',
            'length' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'gallery_urls' => 'array',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
            'is_purchasable' => 'boolean',
            'is_sellable' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Get total stock across all warehouses.
     */
    public function getTotalStock(): float
    {
        return (float) $this->stockLevels()->sum('quantity');
    }

    /**
     * Get available stock (excluding reserved).
     */
    public function getAvailableStock(): float
    {
        return (float) $this->stockLevels()->sum('available_quantity');
    }

    /**
     * Get stock in a specific warehouse.
     */
    public function getStockInWarehouse(int $warehouseId): float
    {
        return (float) $this->stockLevels()
            ->where('warehouse_id', $warehouseId)
            ->sum('quantity');
    }

    /**
     * Check if product is in stock.
     */
    public function isInStock(?int $warehouseId = null): bool
    {
        if ($warehouseId) {
            return $this->getStockInWarehouse($warehouseId) > 0;
        }

        return $this->getTotalStock() > 0;
    }

    /**
     * Check if product needs reordering.
     */
    public function needsReorder(): bool
    {
        if (!$this->reorder_level) {
            return false;
        }

        return $this->getTotalStock() <= $this->reorder_level;
    }

    /**
     * Get average cost from stock levels.
     */
    public function getAverageCost(): float
    {
        $stockLevel = $this->stockLevels()->first();
        return $stockLevel ? (float) $stockLevel->average_cost : (float) $this->purchase_price;
    }

    /**
     * Calculate profit margin.
     */
    public function getProfitMargin(): float
    {
        if ($this->selling_price == 0) {
            return 0;
        }

        $cost = $this->getAverageCost() ?: $this->purchase_price;
        return (($this->selling_price - $cost) / $this->selling_price) * 100;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeGoods($query)
    {
        return $query->where('type', self::TYPE_GOODS);
    }

    public function scopeServices($query)
    {
        return $query->where('type', self::TYPE_SERVICE);
    }

    public function scopePurchasable($query)
    {
        return $query->where('is_purchasable', true);
    }

    public function scopeSellable($query)
    {
        return $query->where('is_sellable', true);
    }

    public function scopeNeedsReorder($query)
    {
        return $query->whereNotNull('reorder_level')
            ->whereHas('stockLevels', function ($q) {
                $q->havingRaw('SUM(quantity) <= products.reorder_level');
            });
    }

    public function scopeInCategory($query, int|array $categoryIds)
    {
        return $query->whereIn('category_id', (array) $categoryIds);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%");
        });
    }
}

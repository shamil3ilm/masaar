<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAuditTrail;
use App\Models\Concerns\HasUuid;
use App\Models\Core\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use HasUuid;
    use BelongsToOrganization;
    use HasAuditTrail;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'name',
        'code',
        'address',
        'city',
        'country_code',
        'phone',
        'email',
        'manager_id',
        'is_default',
        'is_active',
        'allow_negative_stock',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'allow_negative_stock' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function transfersOut(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_warehouse_id');
    }

    public function transfersIn(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_warehouse_id');
    }

    /**
     * Get total stock value in this warehouse.
     */
    public function getTotalStockValue(): float
    {
        return (float) $this->stockLevels()->sum('total_value');
    }

    /**
     * Get unique product count in this warehouse.
     */
    public function getProductCount(): int
    {
        return $this->stockLevels()
            ->where('quantity', '>', 0)
            ->distinct('product_id')
            ->count('product_id');
    }

    /**
     * Check if a product has stock in this warehouse.
     */
    public function hasStock(int $productId, ?int $variantId = null): bool
    {
        $query = $this->stockLevels()
            ->where('product_id', $productId)
            ->where('quantity', '>', 0);

        if ($variantId) {
            $query->where('variant_id', $variantId);
        }

        return $query->exists();
    }

    /**
     * Get stock level for a product.
     */
    public function getStockLevel(int $productId, ?int $variantId = null): ?StockLevel
    {
        $query = $this->stockLevels()->where('product_id', $productId);

        if ($variantId) {
            $query->where('variant_id', $variantId);
        } else {
            $query->whereNull('variant_id');
        }

        return $query->first();
    }

    /**
     * Set as default warehouse.
     */
    public function setAsDefault(): void
    {
        self::where('organization_id', $this->organization_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}

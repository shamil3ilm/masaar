<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLocation extends Model
{
    public const TYPE_ZONE = 'zone';
    public const TYPE_AISLE = 'aisle';
    public const TYPE_RACK = 'rack';
    public const TYPE_SHELF = 'shelf';
    public const TYPE_BIN = 'bin';

    protected $fillable = [
        'warehouse_id',
        'parent_id',
        'name',
        'code',
        'type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class, 'parent_id');
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'location_id');
    }

    /**
     * Get all descendants (children, grandchildren, etc.).
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get all ancestors up to root.
     */
    public function ancestors(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while ($current) {
            $ancestors[] = $current;
            $current = $current->parent;
        }

        return array_reverse($ancestors);
    }

    /**
     * Get the full path (Zone > Aisle > Rack > Shelf > Bin).
     */
    public function getFullPath(): string
    {
        $ancestors = $this->ancestors();
        $path = array_map(fn($a) => $a->name, $ancestors);
        $path[] = $this->name;

        return implode(' > ', $path);
    }

    /**
     * Get the full code path.
     */
    public function getFullCode(): string
    {
        $ancestors = $this->ancestors();
        $codes = array_map(fn($a) => $a->code, $ancestors);
        $codes[] = $this->code;

        return implode('-', $codes);
    }

    /**
     * Check if this location is a leaf (no children).
     */
    public function isLeaf(): bool
    {
        return $this->children()->count() === 0;
    }

    /**
     * Get total stock value at this location.
     */
    public function getTotalStockValue(): float
    {
        return $this->stockLevels()->sum('total_value');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeLeaves($query)
    {
        return $query->whereDoesntHave('children');
    }
}

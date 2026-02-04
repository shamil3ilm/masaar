<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Inventory\Category;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockLevel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * Create a new product with optional variants.
     */
    public function create(array $data, array $variants = []): Product
    {
        return DB::transaction(function () use ($data, $variants) {
            $product = Product::create($data);

            if (!empty($variants)) {
                foreach ($variants as $variantData) {
                    $product->variants()->create($variantData);
                }
                $product->update(['has_variants' => true]);
            }

            return $product->load('variants');
        });
    }

    /**
     * Update a product.
     */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);
        return $product->fresh();
    }

    /**
     * Add variants to a product.
     */
    public function addVariants(Product $product, array $variants): Collection
    {
        return DB::transaction(function () use ($product, $variants) {
            $created = collect();

            foreach ($variants as $variantData) {
                $created->push($product->variants()->create($variantData));
            }

            if (!$product->has_variants) {
                $product->update(['has_variants' => true]);
            }

            return $created;
        });
    }

    /**
     * Update a product variant.
     */
    public function updateVariant(ProductVariant $variant, array $data): ProductVariant
    {
        $variant->update($data);
        return $variant->fresh();
    }

    /**
     * Delete a product (soft delete).
     */
    public function delete(Product $product): bool
    {
        // Check if product has stock
        $hasStock = StockLevel::where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasStock) {
            throw new \InvalidArgumentException(
                'Cannot delete product with existing stock. Adjust stock to zero first.'
            );
        }

        return $product->delete();
    }

    /**
     * Search products with filters.
     */
    public function search(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Product::query()
            ->with(['category', 'unit', 'taxCategory']);

        // Search by term
        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%");
            });
        }

        // Filter by category
        if (!empty($filters['category_id'])) {
            $categoryIds = $this->getCategoryWithDescendants($filters['category_id']);
            $query->whereIn('category_id', $categoryIds);
        }

        // Filter by type
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Filter by active status
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // Filter by stock status
        if (!empty($filters['stock_status'])) {
            $query->whereHas('stockLevels', function (Builder $q) use ($filters) {
                match ($filters['stock_status']) {
                    'in_stock' => $q->where('quantity', '>', 0),
                    'out_of_stock' => $q->where('quantity', '<=', 0),
                    'low_stock' => $q->lowStock(),
                    default => null,
                };
            });
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortDir = $filters['sort_dir'] ?? 'asc';
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($perPage);
    }

    /**
     * Get product with full details.
     */
    public function getWithDetails(Product $product): Product
    {
        return $product->load([
            'category',
            'unit',
            'taxCategory',
            'variants',
            'stockLevels.warehouse',
            'incomeAccount',
            'expenseAccount',
            'inventoryAccount',
        ]);
    }

    /**
     * Get product stock summary.
     */
    public function getStockSummary(Product $product): array
    {
        $stockLevels = StockLevel::with('warehouse')
            ->where('product_id', $product->id)
            ->get();

        return [
            'total_quantity' => $stockLevels->sum('quantity'),
            'total_reserved' => $stockLevels->sum('reserved_quantity'),
            'total_available' => $stockLevels->sum(fn($s) => $s->getAvailableQuantity()),
            'total_value' => $stockLevels->sum('total_value'),
            'by_warehouse' => $stockLevels->map(fn($s) => [
                'warehouse_id' => $s->warehouse_id,
                'warehouse_name' => $s->warehouse->name,
                'quantity' => $s->quantity,
                'reserved' => $s->reserved_quantity,
                'available' => $s->getAvailableQuantity(),
                'average_cost' => $s->average_cost,
                'total_value' => $s->total_value,
            ])->toArray(),
        ];
    }

    /**
     * Clone a product.
     */
    public function clone(Product $product, string $newSku, ?string $newName = null): Product
    {
        return DB::transaction(function () use ($product, $newSku, $newName) {
            $data = $product->replicate([
                'id', 'uuid', 'sku', 'barcode', 'created_at', 'updated_at'
            ])->toArray();

            $data['sku'] = $newSku;
            $data['name'] = $newName ?? $product->name . ' (Copy)';

            $newProduct = Product::create($data);

            // Clone variants if any
            foreach ($product->variants as $variant) {
                $variantData = $variant->replicate([
                    'id', 'sku', 'barcode', 'created_at', 'updated_at'
                ])->toArray();

                $variantData['sku'] = $newSku . '-' . $variant->sku;
                $newProduct->variants()->create($variantData);
            }

            return $newProduct->load('variants');
        });
    }

    /**
     * Update product prices in bulk.
     */
    public function bulkUpdatePrices(array $updates): array
    {
        $results = ['updated' => 0, 'failed' => []];

        DB::transaction(function () use ($updates, &$results) {
            foreach ($updates as $update) {
                try {
                    Product::where('id', $update['product_id'])
                        ->update([
                            'purchase_price' => $update['purchase_price'] ?? DB::raw('purchase_price'),
                            'selling_price' => $update['selling_price'] ?? DB::raw('selling_price'),
                        ]);
                    $results['updated']++;
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'product_id' => $update['product_id'],
                        'error' => $e->getMessage(),
                    ];
                }
            }
        });

        return $results;
    }

    /**
     * Get products that need reordering.
     */
    public function getReorderList(?int $warehouseId = null): Collection
    {
        return StockLevel::with(['product', 'warehouse'])
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->whereRaw('quantity <= COALESCE(reorder_level, 0)')
            ->whereNotNull('reorder_level')
            ->get()
            ->map(fn($stockLevel) => [
                'product_id' => $stockLevel->product_id,
                'product_name' => $stockLevel->product->name,
                'sku' => $stockLevel->product->sku,
                'warehouse_id' => $stockLevel->warehouse_id,
                'warehouse_name' => $stockLevel->warehouse->name,
                'current_quantity' => $stockLevel->quantity,
                'reorder_level' => $stockLevel->reorder_level,
                'reorder_quantity' => $stockLevel->reorder_quantity,
                'shortage' => max(0, $stockLevel->reorder_level - $stockLevel->quantity),
            ]);
    }

    /**
     * Import products from array.
     */
    public function import(array $products): array
    {
        $results = ['created' => 0, 'updated' => 0, 'failed' => []];

        foreach ($products as $index => $data) {
            try {
                $product = Product::updateOrCreate(
                    ['sku' => $data['sku']],
                    $data
                );

                $product->wasRecentlyCreated
                    ? $results['created']++
                    : $results['updated']++;
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'row' => $index + 1,
                    'sku' => $data['sku'] ?? 'N/A',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Get category IDs including all descendants.
     */
    protected function getCategoryWithDescendants(int $categoryId): array
    {
        $category = Category::with('allChildren')->find($categoryId);

        if (!$category) {
            return [$categoryId];
        }

        $ids = [$categoryId];
        $this->collectChildIds($category->allChildren, $ids);

        return $ids;
    }

    /**
     * Recursively collect child category IDs.
     */
    protected function collectChildIds(Collection $children, array &$ids): void
    {
        foreach ($children as $child) {
            $ids[] = $child->id;
            if ($child->allChildren->isNotEmpty()) {
                $this->collectChildIds($child->allChildren, $ids);
            }
        }
    }
}

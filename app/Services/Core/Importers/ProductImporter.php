<?php

declare(strict_types=1);

namespace App\Services\Core\Importers;

use App\Models\Core\ImportJob;
use App\Models\Inventory\Category;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\UnitOfMeasure;
use App\Services\Core\ImporterInterface;

class ProductImporter implements ImporterInterface
{
    public function importRow(array $data, ImportJob $importJob, array $options = []): mixed
    {
        // Check for existing product
        $existing = null;
        if ($options['update_existing'] ?? false) {
            $existing = Product::where('organization_id', $importJob->organization_id)
                ->where('sku', $data['sku'])
                ->first();
        }

        // Resolve category
        $categoryId = null;
        if (!empty($data['category'])) {
            $category = Category::firstOrCreate(
                [
                    'organization_id' => $importJob->organization_id,
                    'name' => $data['category'],
                ],
                [
                    'slug' => \Illuminate\Support\Str::slug($data['category']),
                    'is_active' => true,
                ]
            );
            $categoryId = $category->id;
        }

        // Resolve unit of measure
        $unitId = null;
        if (!empty($data['unit'])) {
            $unit = UnitOfMeasure::firstOrCreate(
                [
                    'organization_id' => $importJob->organization_id,
                    'name' => $data['unit'],
                ],
                [
                    'symbol' => strtoupper(substr($data['unit'], 0, 5)),
                ]
            );
            $unitId = $unit->id;
        }

        $productData = [
            'organization_id' => $importJob->organization_id,
            'sku' => $data['sku'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'goods',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'purchase_price' => $data['purchase_price'] ?? 0,
            'selling_price' => $data['selling_price'] ?? 0,
            'hsn_code' => $data['hsn_code'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'is_active' => true,
        ];

        if ($existing) {
            $existing->update(array_filter($productData, fn ($v) => $v !== null));
            $product = $existing;
        } else {
            $product = Product::create($productData);
        }

        // Handle opening stock
        if (!empty($data['opening_stock']) && $data['opening_stock'] > 0) {
            $this->setOpeningStock($product, (int) $data['opening_stock'], $data['purchase_price'] ?? 0);
        }

        return $product;
    }

    protected function setOpeningStock(Product $product, int $quantity, float $cost): void
    {
        // Find or create default warehouse
        $warehouse = \App\Models\Inventory\Warehouse::where('organization_id', $product->organization_id)
            ->where('is_default', true)
            ->first();

        if (!$warehouse) {
            return;
        }

        StockLevel::updateOrCreate(
            [
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
            ],
            [
                'quantity' => $quantity,
                'average_cost' => $cost,
                'last_purchase_price' => $cost,
                'reorder_level' => $product->reorder_level ?? 10,
            ]
        );
    }
}

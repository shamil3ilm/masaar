<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ProductRequest;
use App\Http\Resources\Inventory\ProductResource;
use App\Models\Inventory\Product;
use App\Services\Inventory\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {}

    /**
     * List products with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = $this->productService->search(
            $request->only([
                'search', 'category_id', 'type', 'is_active', 'stock_status',
                'sort_by', 'sort_dir',
            ]),
            $request->integer('per_page', 15)
        );

        return ProductResource::collection($products);
    }

    /**
     * Create a new product.
     */
    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->productService->create(
            $request->validated(),
            $request->input('variants', [])
        );

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => new ProductResource($product),
        ], 201);
    }

    /**
     * Show a product.
     */
    public function show(Product $product): JsonResponse
    {
        $product = $this->productService->getWithDetails($product);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Update a product.
     */
    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->productService->update($product, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Delete a product.
     */
    public function destroy(Product $product): JsonResponse
    {
        $this->productService->delete($product);

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }

    /**
     * Get product stock summary.
     */
    public function stock(Product $product): JsonResponse
    {
        $summary = $this->productService->getStockSummary($product);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Clone a product.
     */
    public function clone(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'sku' => 'required|string|max:50|unique:products,sku',
            'name' => 'nullable|string|max:200',
        ]);

        $cloned = $this->productService->clone(
            $product,
            $request->input('sku'),
            $request->input('name')
        );

        return response()->json([
            'success' => true,
            'message' => 'Product cloned successfully.',
            'data' => new ProductResource($cloned),
        ], 201);
    }

    /**
     * Get products that need reordering.
     */
    public function reorderList(Request $request): JsonResponse
    {
        $products = $this->productService->getReorderList(
            $request->input('warehouse_id')
        );

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Bulk price update.
     */
    public function bulkUpdatePrices(Request $request): JsonResponse
    {
        $request->validate([
            'updates' => 'required|array|min:1',
            'updates.*.product_id' => 'required|integer|exists:products,id',
            'updates.*.purchase_price' => 'nullable|numeric|min:0',
            'updates.*.selling_price' => 'nullable|numeric|min:0',
        ]);

        $results = $this->productService->bulkUpdatePrices(
            $request->input('updates')
        );

        return response()->json([
            'success' => true,
            'message' => "Updated {$results['updated']} products.",
            'data' => $results,
        ]);
    }
}

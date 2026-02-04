<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\CategoryResource;
use App\Models\Inventory\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * List categories as tree or flat.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Category::query();

        if ($request->boolean('tree', true)) {
            $query->whereNull('parent_id')->with('allChildren');
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $categories = $request->boolean('paginate', false)
            ? $query->paginate($request->integer('per_page', 15))
            : $query->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Create a new category.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|integer|exists:categories,id',
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:categories,slug',
            'description' => 'nullable|string|max:500',
            'image_url' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = \Str::slug($validated['name']);
        }

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully.',
            'data' => new CategoryResource($category),
        ], 201);
    }

    /**
     * Show a category.
     */
    public function show(Category $category): JsonResponse
    {
        $category->load(['parent', 'children', 'products']);

        return response()->json([
            'success' => true,
            'data' => new CategoryResource($category),
        ]);
    }

    /**
     * Update a category.
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|integer|exists:categories,id',
            'name' => 'sometimes|required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:categories,slug,' . $category->id,
            'description' => 'nullable|string|max:500',
            'image_url' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        // Prevent setting parent to self or descendant
        if (isset($validated['parent_id'])) {
            if ($validated['parent_id'] === $category->id) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Category cannot be its own parent.',
                    ],
                ], 422);
            }

            if ($category->isAncestorOf($validated['parent_id'])) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Cannot set a descendant as parent.',
                    ],
                ], 422);
            }
        }

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully.',
            'data' => new CategoryResource($category->fresh()),
        ]);
    }

    /**
     * Delete a category.
     */
    public function destroy(Category $category): JsonResponse
    {
        // Check for products
        if ($category->products()->count() > 0) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Cannot delete category with products. Move products first.',
                ],
            ], 422);
        }

        // Check for children
        if ($category->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Cannot delete category with subcategories.',
                ],
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully.',
        ]);
    }

    /**
     * Move a category to a new parent.
     */
    public function move(Request $request, Category $category): JsonResponse
    {
        $request->validate([
            'parent_id' => 'nullable|integer|exists:categories,id',
        ]);

        $newParentId = $request->input('parent_id');

        if ($newParentId === $category->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Category cannot be its own parent.',
                ],
            ], 422);
        }

        if ($newParentId && $category->isAncestorOf($newParentId)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Cannot move category under its own descendant.',
                ],
            ], 422);
        }

        $category->update(['parent_id' => $newParentId]);

        return response()->json([
            'success' => true,
            'message' => 'Category moved successfully.',
            'data' => new CategoryResource($category->fresh(['parent'])),
        ]);
    }
}

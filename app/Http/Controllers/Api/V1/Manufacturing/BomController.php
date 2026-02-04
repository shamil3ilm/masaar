<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Manufacturing\BomTemplateResource;
use App\Models\Manufacturing\BomTemplate;
use App\Services\Manufacturing\BomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BomController extends Controller
{
    public function __construct(
        private BomService $bomService
    ) {}

    /**
     * List BOM templates with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BomTemplate::with(['product', 'outputUnit', 'defaultWarehouse'])
            ->withCount(['lines', 'operations', 'workOrders'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->product_id, fn($q, $id) => $q->forProduct($id))
            ->when($request->effective === 'true', fn($q) => $q->effective())
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('bom_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy($request->sort_by ?? 'created_at', $request->sort_order ?? 'desc');

        $boms = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return BomTemplateResource::collection($boms);
    }

    /**
     * Store a new BOM template.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bom_number' => 'nullable|string|max:50',
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'output_quantity' => 'required|numeric|min:0.0001',
            'output_unit_id' => 'nullable|exists:units_of_measure,id',
            'default_warehouse_id' => 'nullable|exists:warehouses,id',
            'estimated_hours' => 'nullable|integer|min:0',
            'estimated_labor_cost' => 'nullable|numeric|min:0',
            'overhead_cost' => 'nullable|numeric|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|exists:products,id',
            'lines.*.variant_id' => 'nullable|exists:product_variants,id',
            'lines.*.description' => 'nullable|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'lines.*.wastage_percentage' => 'nullable|numeric|min:0|max:100',
            'lines.*.is_critical' => 'nullable|boolean',
            'lines.*.warehouse_id' => 'nullable|exists:warehouses,id',
            'operations' => 'nullable|array',
            'operations.*.name' => 'required|string|max:100',
            'operations.*.description' => 'nullable|string',
            'operations.*.instructions' => 'nullable|string',
            'operations.*.estimated_minutes' => 'nullable|integer|min:0',
            'operations.*.labor_cost_per_hour' => 'nullable|numeric|min:0',
            'operations.*.workstation' => 'nullable|string|max:100',
            'operations.*.required_skills' => 'nullable|array',
            'operations.*.is_subcontracted' => 'nullable|boolean',
        ]);

        $bom = $this->bomService->create(
            collect($validated)->except(['lines', 'operations'])->toArray(),
            $validated['lines'],
            $validated['operations'] ?? []
        );

        return response()->json([
            'message' => 'BOM template created successfully.',
            'data' => new BomTemplateResource($bom),
        ], 201);
    }

    /**
     * Show a specific BOM template.
     */
    public function show(BomTemplate $bom): BomTemplateResource
    {
        return new BomTemplateResource(
            $bom->load(['product', 'variant', 'outputUnit', 'defaultWarehouse', 'lines.product', 'lines.unit', 'operations', 'createdBy'])
        );
    }

    /**
     * Update a BOM template.
     */
    public function update(Request $request, BomTemplate $bom): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'output_quantity' => 'sometimes|numeric|min:0.0001',
            'output_unit_id' => 'nullable|exists:units_of_measure,id',
            'default_warehouse_id' => 'nullable|exists:warehouses,id',
            'estimated_hours' => 'nullable|integer|min:0',
            'estimated_labor_cost' => 'nullable|numeric|min:0',
            'overhead_cost' => 'nullable|numeric|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'notes' => 'nullable|string',
            'lines' => 'sometimes|array|min:1',
            'lines.*.product_id' => 'required|exists:products,id',
            'lines.*.variant_id' => 'nullable|exists:product_variants,id',
            'lines.*.description' => 'nullable|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'lines.*.wastage_percentage' => 'nullable|numeric|min:0|max:100',
            'lines.*.is_critical' => 'nullable|boolean',
            'lines.*.warehouse_id' => 'nullable|exists:warehouses,id',
            'operations' => 'sometimes|array',
            'operations.*.name' => 'required|string|max:100',
            'operations.*.description' => 'nullable|string',
            'operations.*.instructions' => 'nullable|string',
            'operations.*.estimated_minutes' => 'nullable|integer|min:0',
            'operations.*.labor_cost_per_hour' => 'nullable|numeric|min:0',
            'operations.*.workstation' => 'nullable|string|max:100',
            'operations.*.required_skills' => 'nullable|array',
            'operations.*.is_subcontracted' => 'nullable|boolean',
        ]);

        $bom = $this->bomService->update(
            $bom,
            collect($validated)->except(['lines', 'operations'])->toArray(),
            $validated['lines'] ?? null,
            $validated['operations'] ?? null
        );

        return response()->json([
            'message' => 'BOM template updated successfully.',
            'data' => new BomTemplateResource($bom),
        ]);
    }

    /**
     * Delete a draft BOM template.
     */
    public function destroy(BomTemplate $bom): JsonResponse
    {
        if (!$bom->isDraft()) {
            return response()->json([
                'message' => 'Only draft BOM templates can be deleted.',
            ], 422);
        }

        // Check if used in work orders
        if ($bom->workOrders()->exists()) {
            return response()->json([
                'message' => 'BOM template cannot be deleted. It has associated work orders.',
            ], 422);
        }

        $bom->lines()->delete();
        $bom->operations()->delete();
        $bom->delete();

        return response()->json([
            'message' => 'BOM template deleted successfully.',
        ]);
    }

    /**
     * Activate a BOM template.
     */
    public function activate(BomTemplate $bom): JsonResponse
    {
        $bom = $this->bomService->activate($bom);

        return response()->json([
            'message' => 'BOM template activated successfully.',
            'data' => new BomTemplateResource($bom),
        ]);
    }

    /**
     * Deactivate a BOM template.
     */
    public function deactivate(BomTemplate $bom): JsonResponse
    {
        $bom = $this->bomService->deactivate($bom);

        return response()->json([
            'message' => 'BOM template deactivated successfully.',
            'data' => new BomTemplateResource($bom),
        ]);
    }

    /**
     * Duplicate a BOM template.
     */
    public function duplicate(Request $request, BomTemplate $bom): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:200',
            'product_id' => 'nullable|exists:products,id',
        ]);

        $newBom = $this->bomService->duplicate($bom, $validated);

        return response()->json([
            'message' => 'BOM template duplicated successfully.',
            'data' => new BomTemplateResource($newBom),
        ], 201);
    }

    /**
     * Get cost breakdown for a BOM template.
     */
    public function costBreakdown(Request $request, BomTemplate $bom): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'nullable|numeric|min:0.0001',
        ]);

        $quantity = (float) ($validated['quantity'] ?? $bom->output_quantity);
        $breakdown = $this->bomService->getCostBreakdown($bom, $quantity);

        return response()->json([
            'data' => $breakdown,
        ]);
    }

    /**
     * Check material availability for production.
     */
    public function checkAvailability(Request $request, BomTemplate $bom): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.0001',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $availability = $this->bomService->checkAvailability(
            $bom,
            (float) $validated['quantity'],
            $validated['warehouse_id'] ?? null
        );

        return response()->json([
            'data' => $availability,
        ]);
    }

    /**
     * Get BOMs for a specific product.
     */
    public function forProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'active_only' => 'nullable|boolean',
        ]);

        $boms = $this->bomService->getForProduct(
            (int) $validated['product_id'],
            $validated['active_only'] ?? true
        );

        return response()->json([
            'data' => BomTemplateResource::collection($boms),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\PricingConditionRecord;
use App\Models\Sales\PricingConditionType;
use App\Models\Sales\PricingProcedure;
use App\Services\Sales\PricingConditionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingConditionController extends Controller
{
    public function __construct(
        private PricingConditionService $service
    ) {}

    // -------------------------------------------------------------------------
    // Pricing Procedures
    // -------------------------------------------------------------------------

    public function indexProcedures(Request $request): JsonResponse
    {
        $procedures = PricingProcedure::when($request->boolean('active_only'), fn($q) => $q->active())
            ->orderBy('code')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($procedures, \App\Http\Resources\Sales\PricingProcedureResource::class);
    }

    public function storeProcedure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:100',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        try {
            $procedure = $this->service->storeProcedure($validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->created($procedure, 'Pricing procedure created successfully.');
    }

    public function showProcedure(PricingProcedure $pricingProcedure): JsonResponse
    {
        return $this->success($pricingProcedure->load('conditionTypes'));
    }

    public function updateProcedure(Request $request, PricingProcedure $pricingProcedure): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|max:20',
            'name' => 'sometimes|string|max:100',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        return $this->tryAction(
            fn() => $this->service->updateProcedure($pricingProcedure, $validated),
            'Pricing procedure updated successfully.',
            'VALIDATION_ERROR'
        );
    }

    public function destroyProcedure(PricingProcedure $pricingProcedure): JsonResponse
    {
        $pricingProcedure->delete();

        return $this->success(null, 'Pricing procedure deleted successfully.');
    }

    // -------------------------------------------------------------------------
    // Condition Types
    // -------------------------------------------------------------------------

    public function indexConditionTypes(Request $request): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        $types = PricingConditionType::where('organization_id', $organizationId)
            ->when($request->condition_class, fn($q, $v) => $q->byClass($v))
            ->ordered()
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($types, \App\Http\Resources\Sales\PricingConditionTypeResource::class);
    }

    public function storeConditionType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10',
            'name' => 'required|string|max:100',
            'condition_class' => 'required|in:price,discount,surcharge,tax,freight',
            'calculation_type' => 'required|in:fixed,percentage,quantity,weight,volume',
            'is_mandatory' => 'boolean',
            'step' => 'integer|min:1',
            'counter' => 'integer|min:0',
        ]);

        try {
            $type = $this->service->storeConditionType($validated);
        } catch (\Exception $e) {
            report($e);
            return $this->error('Failed to create condition type.', 'SERVER_ERROR', 500);
        }

        return $this->created($type, 'Condition type created successfully.');
    }

    public function showConditionType(PricingConditionType $pricingConditionType): JsonResponse
    {
        return $this->success($pricingConditionType->load('records'));
    }

    public function updateConditionType(Request $request, PricingConditionType $pricingConditionType): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'condition_class' => 'sometimes|in:price,discount,surcharge,tax,freight',
            'calculation_type' => 'sometimes|in:fixed,percentage,quantity,weight,volume',
            'is_mandatory' => 'boolean',
            'step' => 'integer|min:1',
            'counter' => 'integer|min:0',
        ]);

        $type = $this->service->updateConditionType($pricingConditionType, $validated);

        return $this->success($type, 'Condition type updated successfully.');
    }

    public function destroyConditionType(PricingConditionType $pricingConditionType): JsonResponse
    {
        $pricingConditionType->delete();

        return $this->success(null, 'Condition type deleted successfully.');
    }

    // -------------------------------------------------------------------------
    // Condition Records
    // -------------------------------------------------------------------------

    public function indexConditionRecords(Request $request): JsonResponse
    {
        $records = $this->service->indexConditionRecords($request->only([
            'condition_type_id',
            'product_id',
            'customer_id',
            'key_combination',
            'is_active',
            'currency_code',
            'per_page',
        ]));

        return $this->paginated($records, \App\Http\Resources\Sales\PricingConditionRecordResource::class);
    }

    public function storeConditionRecord(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'condition_type_id' => 'required|exists:pricing_condition_types,id',
            'key_combination' => 'required|in:customer_material,customer,material,price_list,all',
            'customer_id' => 'nullable|exists:contacts,id',
            'product_id' => 'nullable|exists:products,id',
            'price_list_id' => 'nullable|exists:price_lists,id',
            'rate' => 'required|numeric',
            'currency_code' => 'required|string|size:3',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'min_quantity' => 'nullable|numeric|min:0',
            'max_quantity' => 'nullable|numeric|gt:min_quantity',
            'is_active' => 'boolean',
        ]);

        try {
            $record = $this->service->storeConditionRecord($validated);
        } catch (\Exception $e) {
            report($e);
            return $this->error('Failed to create condition record.', 'SERVER_ERROR', 500);
        }

        return $this->created($record->load('conditionType'), 'Condition record created successfully.');
    }

    public function showConditionRecord(PricingConditionRecord $pricingConditionRecord): JsonResponse
    {
        return $this->success($pricingConditionRecord->load('conditionType'));
    }

    public function updateConditionRecord(Request $request, PricingConditionRecord $pricingConditionRecord): JsonResponse
    {
        $validated = $request->validate([
            'rate' => 'sometimes|numeric',
            'currency_code' => 'sometimes|string|size:3',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'min_quantity' => 'nullable|numeric|min:0',
            'max_quantity' => 'nullable|numeric',
            'is_active' => 'boolean',
        ]);

        $record = $this->service->updateConditionRecord($pricingConditionRecord, $validated);

        return $this->success($record->load('conditionType'), 'Condition record updated successfully.');
    }

    public function destroyConditionRecord(PricingConditionRecord $pricingConditionRecord): JsonResponse
    {
        $pricingConditionRecord->delete();

        return $this->success(null, 'Condition record deleted successfully.');
    }

    // -------------------------------------------------------------------------
    // Resolve price for a line
    // -------------------------------------------------------------------------

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'required|exists:contacts,id',
            'quantity' => 'required|numeric|min:0.0001',
            'currency' => 'required|string|size:3',
        ]);

        try {
            $result = $this->service->resolvePriceForLine(
                (int) $validated['product_id'],
                (int) $validated['customer_id'],
                (float) $validated['quantity'],
                $validated['currency']
            );
        } catch (\Exception $e) {
            report($e);
            return $this->error('Failed to resolve pricing.', 'SERVER_ERROR', 500);
        }

        return $this->success($result);
    }
}

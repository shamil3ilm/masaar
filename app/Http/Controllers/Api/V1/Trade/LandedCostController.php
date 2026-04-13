<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trade;

use App\Http\Controllers\Controller;
use App\Models\Trade\LandedCostVoucher;
use App\Services\Trade\LandedCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandedCostController extends Controller
{
    public function __construct(
        private LandedCostService $landedCostService
    ) {
    }

    /**
     * List landed cost vouchers.
     */
    public function index(Request $request): JsonResponse
    {
        $query = LandedCostVoucher::with(['createdBy:id,name', 'shipment:id,shipment_number'])
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->when($request->has('status'), fn($q) => $q->forStatus($request->input('status')))
            ->when($request->has('search'), fn($q) => $q->where('voucher_number', 'like', "%{$request->input('search')}%"));

        $vouchers = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($vouchers);
    }

    /**
     * Create a landed cost voucher.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'voucher_date' => ['required', 'date'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'shipment_id' => ['nullable', 'exists:import_export_shipments,id'],
            'bill_id' => ['nullable', 'exists:bills,id'],
            'currency_code' => ['required', 'string', 'max:3'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0.00000001'],
            'allocation_method' => ['sometimes', 'in:value,quantity,weight,volume,manual'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.purchase_value' => ['required', 'numeric', 'min:0'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.volume_cbm' => ['nullable', 'numeric', 'min:0'],
            'charges' => ['sometimes', 'array'],
            'charges.*.charge_type' => ['required', 'in:customs_duty,freight,insurance,clearing_charges,port_charges,handling,demurrage,inspection,fumigation,documentation,exchange_difference,other'],
            'charges.*.description' => ['required', 'string'],
            'charges.*.vendor_id' => ['nullable', 'exists:contacts,id'],
            'charges.*.bill_id' => ['nullable', 'exists:bills,id'],
            'charges.*.amount' => ['required', 'numeric', 'min:0'],
            'charges.*.currency_code' => ['required', 'string', 'max:3'],
            'charges.*.exchange_rate' => ['sometimes', 'numeric', 'min:0.00000001'],
            'charges.*.base_amount' => ['sometimes', 'numeric', 'min:0'],
            'charges.*.account_id' => ['nullable', 'exists:chart_of_accounts,id'],
        ]);

        $validated['organization_id'] = $this->organizationId($request);

        try {
            $voucher = $this->landedCostService->create($validated);
            return $this->created($voucher);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    /**
     * Show a landed cost voucher.
     */
    public function show(LandedCostVoucher $landedCostVoucher): JsonResponse
    {
        $summary = $this->landedCostService->getVoucherSummary($landedCostVoucher);

        return $this->success($summary);
    }

    /**
     * Update a landed cost voucher.
     */
    public function update(Request $request, LandedCostVoucher $landedCostVoucher): JsonResponse
    {
        if (!$landedCostVoucher->isEditable()) {
            return $this->error('Only draft vouchers can be updated.', 'INVALID_STATUS', 400);
        }

        $validated = $request->validate([
            'voucher_date' => ['sometimes', 'date'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'shipment_id' => ['nullable', 'exists:import_export_shipments,id'],
            'bill_id' => ['nullable', 'exists:bills,id'],
            'currency_code' => ['sometimes', 'string', 'max:3'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0.00000001'],
            'allocation_method' => ['sometimes', 'in:value,quantity,weight,volume,manual'],
            'notes' => ['nullable', 'string'],
        ]);

        $landedCostVoucher->update($validated);

        return $this->success($landedCostVoucher->fresh(), 'Landed cost voucher updated successfully');
    }

    /**
     * Delete a landed cost voucher.
     */
    public function destroy(LandedCostVoucher $landedCostVoucher): JsonResponse
    {
        if (!$landedCostVoucher->isEditable()) {
            return $this->error('Only draft vouchers can be deleted.', 'INVALID_STATUS', 400);
        }

        $landedCostVoucher->items()->delete();
        $landedCostVoucher->charges()->delete();
        $landedCostVoucher->delete();

        return $this->success(null, 'Landed cost voucher deleted successfully');
    }

    /**
     * Add items to a voucher.
     */
    public function addItems(Request $request, LandedCostVoucher $landedCostVoucher): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.purchase_value' => ['required', 'numeric', 'min:0'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.volume_cbm' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->tryAction(
            fn() => $this->landedCostService->addItems($landedCostVoucher, $validated['items']),
            'Items added successfully',
            'VALIDATION_ERROR',
            400
        );
    }

    /**
     * Add charges to a voucher.
     */
    public function addCharges(Request $request, LandedCostVoucher $landedCostVoucher): JsonResponse
    {
        $validated = $request->validate([
            'charges' => ['required', 'array', 'min:1'],
            'charges.*.charge_type' => ['required', 'in:customs_duty,freight,insurance,clearing_charges,port_charges,handling,demurrage,inspection,fumigation,documentation,exchange_difference,other'],
            'charges.*.description' => ['required', 'string'],
            'charges.*.vendor_id' => ['nullable', 'exists:contacts,id'],
            'charges.*.bill_id' => ['nullable', 'exists:bills,id'],
            'charges.*.amount' => ['required', 'numeric', 'min:0'],
            'charges.*.currency_code' => ['required', 'string', 'max:3'],
            'charges.*.exchange_rate' => ['sometimes', 'numeric', 'min:0.00000001'],
            'charges.*.base_amount' => ['sometimes', 'numeric', 'min:0'],
            'charges.*.account_id' => ['nullable', 'exists:chart_of_accounts,id'],
        ]);

        return $this->tryAction(
            fn() => $this->landedCostService->addCharges($landedCostVoucher, $validated['charges']),
            'Charges added successfully',
            'VALIDATION_ERROR',
            400
        );
    }

    /**
     * Allocate charges to items.
     */
    public function allocate(Request $request, LandedCostVoucher $landedCostVoucher): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['sometimes', 'in:value,quantity,weight,volume,manual'],
        ]);

        return $this->tryAction(
            fn() => $this->landedCostService->allocate($landedCostVoucher, $validated['method'] ?? $landedCostVoucher->allocation_method),
            'Charges allocated successfully',
            'ALLOCATION_FAILED',
            400
        );
    }

    /**
     * Post a landed cost voucher.
     */
    public function post(LandedCostVoucher $landedCostVoucher): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->landedCostService->post($landedCostVoucher),
            'Landed cost voucher posted successfully',
            'POST_FAILED',
            400
        );
    }
}

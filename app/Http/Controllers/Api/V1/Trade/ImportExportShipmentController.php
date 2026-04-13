<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trade;

use App\Http\Controllers\Controller;
use App\Models\Trade\ImportExportShipment;
use App\Services\Trade\ImportExportShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportExportShipmentController extends Controller
{
    public function __construct(
        private ImportExportShipmentService $shipmentService
    ) {
    }

    /**
     * List shipments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ImportExportShipment::with(['contact:id,name', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->when($request->has('shipment_type'), fn($q) => $q->forType($request->input('shipment_type')))
            ->when($request->has('status'), fn($q) => $q->forStatus($request->input('status')))
            ->when($request->has('contact_id'), fn($q) => $q->forContact($request->integer('contact_id')))
            ->when($request->boolean('in_transit'), fn($q) => $q->inTransit())
            ->when($request->has('search'), function ($q) use ($request) {
                $search = $request->input('search');
                $q->where(function ($q) use ($search) {
                    $q->where('shipment_number', 'like', "%{$search}%")
                        ->orWhere('bill_of_lading', 'like', "%{$search}%")
                        ->orWhere('airway_bill', 'like', "%{$search}%")
                        ->orWhere('vessel_name', 'like', "%{$search}%");
                });
            });

        $shipments = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($shipments);
    }

    /**
     * Create a new shipment.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'shipment_type' => ['required', 'in:import,export'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'transport_mode' => ['required', 'in:sea,air,road,rail,multimodal,courier'],
            'vessel_name' => ['nullable', 'string', 'max:255'],
            'voyage_number' => ['nullable', 'string', 'max:50'],
            'container_numbers' => ['nullable', 'array'],
            'bill_of_lading' => ['nullable', 'string', 'max:50'],
            'airway_bill' => ['nullable', 'string', 'max:50'],
            'port_of_loading' => ['nullable', 'string', 'max:255'],
            'port_of_discharge' => ['nullable', 'string', 'max:255'],
            'place_of_delivery' => ['nullable', 'string', 'max:255'],
            'country_of_origin' => ['nullable', 'string', 'max:3'],
            'country_of_destination' => ['nullable', 'string', 'max:3'],
            'estimated_departure' => ['nullable', 'date'],
            'estimated_arrival' => ['nullable', 'date'],
            'currency_code' => ['required', 'string', 'max:3'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0.00000001'],
            'fob_value' => ['sometimes', 'numeric', 'min:0'],
            'freight_value' => ['sometimes', 'numeric', 'min:0'],
            'insurance_value' => ['sometimes', 'numeric', 'min:0'],
            'other_charges' => ['sometimes', 'numeric', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'total_packages' => ['nullable', 'integer', 'min:0'],
            'total_cbm' => ['nullable', 'numeric', 'min:0'],
            'insurance_policy_number' => ['nullable', 'string', 'max:255'],
            'insurance_company' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.total_value' => ['required', 'numeric', 'min:0'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.tariff_code' => ['nullable', 'string', 'max:12'],
            'items.*.country_of_origin' => ['nullable', 'string', 'max:3'],
        ]);

        $validated['organization_id'] = $this->organizationId($request);

        try {
            $shipment = $this->shipmentService->create($validated);
            return $this->created($shipment);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    /**
     * Show a shipment.
     */
    public function show(ImportExportShipment $importExportShipment): JsonResponse
    {
        $importExportShipment->load([
            'items.product:id,name,sku',
            'items.variant:id,name',
            'contact',
            'branch:id,name',
            'purchaseOrder:id,po_number',
            'invoice:id,invoice_number',
            'customsDeclaration:id,declaration_number,status',
            'letterOfCredit:id,lc_number,status',
            'landedCostVoucher:id,voucher_number,status',
            'createdBy:id,name',
        ]);

        return $this->success($importExportShipment);
    }

    /**
     * Update a shipment.
     */
    public function update(Request $request, ImportExportShipment $importExportShipment): JsonResponse
    {
        $validated = $request->validate([
            'incoterm' => ['nullable', 'string', 'max:10'],
            'transport_mode' => ['sometimes', 'in:sea,air,road,rail,multimodal,courier'],
            'vessel_name' => ['nullable', 'string', 'max:255'],
            'voyage_number' => ['nullable', 'string', 'max:50'],
            'container_numbers' => ['nullable', 'array'],
            'bill_of_lading' => ['nullable', 'string', 'max:50'],
            'airway_bill' => ['nullable', 'string', 'max:50'],
            'port_of_loading' => ['nullable', 'string', 'max:255'],
            'port_of_discharge' => ['nullable', 'string', 'max:255'],
            'place_of_delivery' => ['nullable', 'string', 'max:255'],
            'estimated_departure' => ['nullable', 'date'],
            'estimated_arrival' => ['nullable', 'date'],
            'actual_departure' => ['nullable', 'date'],
            'actual_arrival' => ['nullable', 'date'],
            'fob_value' => ['sometimes', 'numeric', 'min:0'],
            'freight_value' => ['sometimes', 'numeric', 'min:0'],
            'insurance_value' => ['sometimes', 'numeric', 'min:0'],
            'other_charges' => ['sometimes', 'numeric', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'total_packages' => ['nullable', 'integer', 'min:0'],
            'insurance_policy_number' => ['nullable', 'string', 'max:255'],
            'insurance_company' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->tryAction(
            fn() => $this->shipmentService->update($importExportShipment, $validated),
            'Shipment updated successfully',
            'VALIDATION_ERROR',
            400
        );
    }

    /**
     * Delete a shipment.
     */
    public function destroy(ImportExportShipment $importExportShipment): JsonResponse
    {
        if (!in_array($importExportShipment->status, [ImportExportShipment::STATUS_PENDING])) {
            return $this->error('Only pending shipments can be deleted.', 'INVALID_STATUS', 400);
        }

        $importExportShipment->items()->delete();
        $importExportShipment->delete();

        return $this->success(null, 'Shipment deleted successfully');
    }

    /**
     * Update shipment status.
     */
    public function updateStatus(Request $request, ImportExportShipment $importExportShipment): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:in_transit,at_port,customs_clearance,cleared,delivered,cancelled'],
            'actual_departure' => ['nullable', 'date'],
            'actual_arrival' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->tryAction(
            fn() => $this->shipmentService->updateStatus(
                $importExportShipment,
                $validated['status'],
                collect($validated)->except('status')->filter()->toArray()
            ),
            'Shipment status updated successfully',
            'STATUS_UPDATE_FAILED',
            400
        );
    }

    /**
     * Add items to a shipment.
     */
    public function addItems(Request $request, ImportExportShipment $importExportShipment): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.total_value' => ['required', 'numeric', 'min:0'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.tariff_code' => ['nullable', 'string', 'max:12'],
            'items.*.country_of_origin' => ['nullable', 'string', 'max:3'],
        ]);

        $shipment = $this->shipmentService->addItems($importExportShipment, $validated['items']);

        return $this->success($shipment, 'Items added to shipment successfully');
    }

    /**
     * Link a customs declaration or LC to a shipment.
     */
    public function link(Request $request, ImportExportShipment $importExportShipment): JsonResponse
    {
        $validated = $request->validate([
            'customs_declaration_id' => ['nullable', 'exists:customs_declarations,id'],
            'lc_id' => ['nullable', 'exists:letters_of_credit,id'],
        ]);

        if (isset($validated['customs_declaration_id'])) {
            $importExportShipment = $this->shipmentService->linkCustomsDeclaration(
                $importExportShipment,
                $validated['customs_declaration_id']
            );
        }

        if (isset($validated['lc_id'])) {
            $importExportShipment = $this->shipmentService->linkLC(
                $importExportShipment,
                $validated['lc_id']
            );
        }

        return $this->success($importExportShipment->fresh(), 'Shipment linked successfully');
    }
}

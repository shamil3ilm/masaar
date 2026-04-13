<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customs;

use App\Http\Controllers\Controller;
use App\Models\Customs\CustomsDeclaration;
use App\Services\Customs\CustomsDeclarationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomsDeclarationController extends Controller
{
    public function __construct(
        private CustomsDeclarationService $declarationService
    ) {
    }

    /**
     * List customs declarations with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CustomsDeclaration::with(['importerExporter:id,name', 'createdBy:id,name'])
            ->orderByDesc('declaration_date')
            ->orderByDesc('id')
            ->when($request->has('type'), fn($q) => $q->forType($request->input('type')))
            ->when($request->has('status'), fn($q) => $q->forStatus($request->input('status')))
            ->when($request->has('start_date') && $request->has('end_date'), fn($q) => $q->forDateRange($request->input('start_date'), $request->input('end_date')))
            ->when($request->has('search'), function ($q) use ($request) {
                $search = $request->input('search');
                $q->where(function ($q) use ($search) {
                    $q->where('declaration_number', 'like', "%{$search}%")
                        ->orWhere('bill_of_entry_number', 'like', "%{$search}%")
                        ->orWhere('shipping_bill_number', 'like', "%{$search}%");
                });
            });

        $declarations = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($declarations);
    }

    /**
     * Resolve a customs declaration by ID with organization scoping.
     */
    private function resolveDeclaration(int $id): ?CustomsDeclaration
    {
        return CustomsDeclaration::find($id);
    }

    /**
     * Create a new customs declaration.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'declaration_type' => ['required', 'in:import,export,transit,re_export,temporary_import,temporary_export'],
            'customs_regime' => ['nullable', 'string', 'max:100'],
            'importer_exporter_id' => ['nullable', 'exists:contacts,id'],
            'broker_id' => ['nullable', 'exists:contacts,id'],
            'consignee_name' => ['nullable', 'string', 'max:255'],
            'consignor_name' => ['nullable', 'string', 'max:255'],
            'customs_office' => ['nullable', 'string', 'max:100'],
            'port_of_entry' => ['nullable', 'string', 'max:100'],
            'port_of_exit' => ['nullable', 'string', 'max:100'],
            'country_of_origin' => ['nullable', 'string', 'max:3'],
            'country_of_destination' => ['nullable', 'string', 'max:3'],
            'country_of_consignment' => ['nullable', 'string', 'max:3'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'transport_mode' => ['nullable', 'string', 'max:50'],
            'vessel_name' => ['nullable', 'string', 'max:255'],
            'voyage_flight_number' => ['nullable', 'string', 'max:50'],
            'currency_code' => ['nullable', 'string', 'max:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'fob_value' => ['nullable', 'numeric', 'min:0'],
            'freight_value' => ['nullable', 'numeric', 'min:0'],
            'insurance_value' => ['nullable', 'numeric', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'total_packages' => ['nullable', 'integer', 'min:0'],
            'package_type' => ['nullable', 'string', 'max:50'],
            'declaration_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.description' => ['required', 'string'],
            'items.*.tariff_code' => ['nullable', 'string', 'max:12'],
            'items.*.tariff_id' => ['nullable', 'exists:customs_tariff_codes,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_value' => ['required', 'numeric', 'min:0'],
            'items.*.total_value' => ['required', 'numeric', 'min:0'],
            'items.*.duty_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.country_of_origin' => ['nullable', 'string', 'max:3'],
        ]);

        $validated['organization_id'] = $this->organizationId($request);

        try {
            $declaration = $this->declarationService->create($validated);
            return $this->created($declaration);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred. Please try again.', 'SERVER_ERROR', 500);
        }
    }

    /**
     * Show a customs declaration.
     */
    public function show(int $declaration): JsonResponse
    {
        $customsDeclaration = $this->resolveDeclaration($declaration);

        if (!$customsDeclaration) {
            return $this->notFound('Customs declaration not found');
        }

        $customsDeclaration->load([
            'items.tariff',
            'items.product:id,name,sku',
            'importerExporter',
            'broker',
            'branch:id,name',
            'journalEntry',
            'createdBy:id,name',
        ]);

        return $this->success($customsDeclaration);
    }

    /**
     * Update a customs declaration.
     */
    public function update(Request $request, int $declaration): JsonResponse
    {
        $customsDeclaration = $this->resolveDeclaration($declaration);

        if (!$customsDeclaration) {
            return $this->notFound('Customs declaration not found');
        }

        $validated = $request->validate([
            'customs_regime' => ['nullable', 'string', 'max:100'],
            'importer_exporter_id' => ['nullable', 'exists:contacts,id'],
            'broker_id' => ['nullable', 'exists:contacts,id'],
            'consignee_name' => ['nullable', 'string', 'max:255'],
            'consignor_name' => ['nullable', 'string', 'max:255'],
            'customs_office' => ['nullable', 'string', 'max:100'],
            'port_of_entry' => ['nullable', 'string', 'max:100'],
            'port_of_exit' => ['nullable', 'string', 'max:100'],
            'country_of_origin' => ['nullable', 'string', 'max:3'],
            'country_of_destination' => ['nullable', 'string', 'max:3'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'transport_mode' => ['nullable', 'string', 'max:50'],
            'vessel_name' => ['nullable', 'string', 'max:255'],
            'voyage_flight_number' => ['nullable', 'string', 'max:50'],
            'currency_code' => ['nullable', 'string', 'max:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'fob_value' => ['nullable', 'numeric', 'min:0'],
            'freight_value' => ['nullable', 'numeric', 'min:0'],
            'insurance_value' => ['nullable', 'numeric', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'total_packages' => ['nullable', 'integer', 'min:0'],
            'declaration_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.tariff_code' => ['nullable', 'string', 'max:12'],
            'items.*.tariff_id' => ['nullable', 'exists:customs_tariff_codes,id'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $result = $this->declarationService->update($customsDeclaration, $validated);
            return $this->success($result, 'Customs declaration updated successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred. Please try again.', 'SERVER_ERROR', 500);
        }
    }

    /**
     * Delete a customs declaration.
     */
    public function destroy(int $declaration): JsonResponse
    {
        $customsDeclaration = $this->resolveDeclaration($declaration);

        if (!$customsDeclaration) {
            return $this->notFound('Customs declaration not found');
        }

        if (!$customsDeclaration->isEditable()) {
            return $this->error('Only draft declarations can be deleted.', 'INVALID_STATUS', 400);
        }

        $customsDeclaration->items()->delete();
        $customsDeclaration->delete();

        return $this->success(null, 'Customs declaration deleted successfully');
    }

    /**
     * Submit a declaration for assessment.
     */
    public function submit(int $declaration): JsonResponse
    {
        $customsDeclaration = $this->resolveDeclaration($declaration);

        if (!$customsDeclaration) {
            return $this->notFound('Customs declaration not found');
        }

        try {
            $result = $this->declarationService->submit($customsDeclaration);
            return $this->success($result, 'Declaration submitted successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'SUBMIT_FAILED', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred. Please try again.', 'SERVER_ERROR', 500);
        }
    }

    /**
     * Assess a submitted declaration.
     */
    public function assess(Request $request, int $declaration): JsonResponse
    {
        $customsDeclaration = $this->resolveDeclaration($declaration);

        if (!$customsDeclaration) {
            return $this->notFound('Customs declaration not found');
        }

        $validated = $request->validate([
            'assessable_value' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'exists:customs_declaration_items,id'],
            'items.*.assessable_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.duty_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->tryAction(
            fn() => $this->declarationService->assess($customsDeclaration, $validated),
            'Declaration assessed successfully',
            'ASSESS_FAILED'
        );
    }

    /**
     * Record duty payment.
     */
    public function payDuty(Request $request, int $declaration): JsonResponse
    {
        $customsDeclaration = $this->resolveDeclaration($declaration);

        if (!$customsDeclaration) {
            return $this->notFound('Customs declaration not found');
        }

        $validated = $request->validate([
            'journal_entry_id' => ['nullable', 'exists:journal_entries,id'],
        ]);

        return $this->tryAction(
            fn() => $this->declarationService->payDuty($customsDeclaration, $validated),
            'Duty payment recorded successfully',
            'PAYMENT_FAILED'
        );
    }

    /**
     * Clear a declaration.
     */
    public function clear(int $declaration): JsonResponse
    {
        $customsDeclaration = $this->resolveDeclaration($declaration);

        if (!$customsDeclaration) {
            return $this->notFound('Customs declaration not found');
        }

        try {
            $result = $this->declarationService->clear($customsDeclaration);
            return $this->success($result, 'Declaration cleared successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'CLEAR_FAILED', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred. Please try again.', 'SERVER_ERROR', 500);
        }
    }

    /**
     * Reject a declaration.
     */
    public function reject(Request $request, int $declaration): JsonResponse
    {
        $customsDeclaration = $this->resolveDeclaration($declaration);

        if (!$customsDeclaration) {
            return $this->notFound('Customs declaration not found');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->tryAction(
            fn() => $this->declarationService->reject($customsDeclaration, $validated['reason']),
            'Declaration rejected',
            'REJECT_FAILED'
        );
    }
}

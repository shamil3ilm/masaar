<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customs;

use App\Http\Controllers\Controller;
use App\Models\Customs\ExciseDeclaration;
use App\Services\Customs\ExciseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExciseDeclarationController extends Controller
{
    public function __construct(
        private ExciseService $exciseService
    ) {
    }

    /**
     * Resolve an excise declaration by ID with organization scoping.
     */
    private function resolveDeclaration(int $id): ?ExciseDeclaration
    {
        return ExciseDeclaration::find($id);
    }

    /**
     * List excise declarations.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'declaration_type', 'period_from', 'period_to']);

        $declarations = $this->exciseService->getDeclarations(
            $filters,
            $request->integer('per_page', 20)
        );

        return $this->paginated($declarations);
    }

    /**
     * Create a new excise declaration.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'declaration_type' => ['nullable', 'in:periodic,ad_hoc,amendment'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'total_deductions' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.excise_category_id' => ['nullable', 'exists:excise_categories,id'],
            'items.*.excise_rate_id' => ['nullable', 'exists:excise_rates,id'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.excisable_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.excise_rate_applied' => ['nullable', 'numeric', 'min:0'],
            'items.*.excise_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['organization_id'] = $this->organizationId($request);

        try {
            $declaration = $this->exciseService->createDeclaration($validated);
            return $this->created($declaration);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred. Please try again.', 'SERVER_ERROR', 500);
        }
    }

    /**
     * Show a single excise declaration.
     */
    public function show(int $declaration): JsonResponse
    {
        $exciseDeclaration = $this->resolveDeclaration($declaration);

        if (!$exciseDeclaration) {
            return $this->notFound('Excise declaration not found');
        }

        $exciseDeclaration->load([
            'items.exciseCategory',
            'items.exciseRate',
            'items.product:id,name,sku',
            'journalEntry',
            'createdBy:id,name',
        ]);

        return $this->success($exciseDeclaration);
    }

    /**
     * Update an excise declaration.
     */
    public function update(Request $request, int $declaration): JsonResponse
    {
        $exciseDeclaration = $this->resolveDeclaration($declaration);

        if (!$exciseDeclaration) {
            return $this->notFound('Excise declaration not found');
        }

        if (!$exciseDeclaration->isEditable()) {
            return $this->error('Only draft declarations can be updated.', 'INVALID_STATUS', 400);
        }

        $validated = $request->validate([
            'declaration_type' => ['nullable', 'in:periodic,ad_hoc,amendment'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date'],
            'total_deductions' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $exciseDeclaration->update($validated);

        if (isset($validated['total_deductions'])) {
            $exciseDeclaration->recalculateTotals();
        }

        return $this->success($exciseDeclaration->fresh(['items']), 'Excise declaration updated successfully');
    }

    /**
     * Delete an excise declaration.
     */
    public function destroy(int $declaration): JsonResponse
    {
        $exciseDeclaration = $this->resolveDeclaration($declaration);

        if (!$exciseDeclaration) {
            return $this->notFound('Excise declaration not found');
        }

        if (!$exciseDeclaration->isEditable()) {
            return $this->error('Only draft declarations can be deleted.', 'INVALID_STATUS', 400);
        }

        $exciseDeclaration->items()->delete();
        $exciseDeclaration->delete();

        return $this->success(null, 'Excise declaration deleted successfully');
    }

    /**
     * Submit a declaration.
     */
    public function submit(int $declaration): JsonResponse
    {
        $exciseDeclaration = $this->resolveDeclaration($declaration);

        if (!$exciseDeclaration) {
            return $this->notFound('Excise declaration not found');
        }

        try {
            $result = $this->exciseService->submitDeclaration($exciseDeclaration);
            return $this->success($result, 'Excise declaration submitted successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'SUBMIT_FAILED', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred. Please try again.', 'SERVER_ERROR', 500);
        }
    }

    /**
     * Pay a declaration.
     */
    public function pay(Request $request, int $declaration): JsonResponse
    {
        $exciseDeclaration = $this->resolveDeclaration($declaration);

        if (!$exciseDeclaration) {
            return $this->notFound('Excise declaration not found');
        }

        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'journal_entry_id' => ['nullable', 'exists:journal_entries,id'],
        ]);

        return $this->tryAction(
            fn() => $this->exciseService->payDeclaration($exciseDeclaration, $validated),
            'Excise declaration payment recorded successfully',
            'PAYMENT_FAILED',
            400
        );
    }

    /**
     * Add items to an existing declaration.
     */
    public function addItems(Request $request, int $declaration): JsonResponse
    {
        $exciseDeclaration = $this->resolveDeclaration($declaration);

        if (!$exciseDeclaration) {
            return $this->notFound('Excise declaration not found');
        }

        if (!$exciseDeclaration->isEditable()) {
            return $this->error('Only draft declarations can have items added.', 'INVALID_STATUS', 400);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.excise_category_id' => ['required', 'exists:excise_categories,id'],
            'items.*.excise_rate_id' => ['nullable', 'exists:excise_rates,id'],
            'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.excisable_value' => ['required', 'numeric', 'min:0'],
            'items.*.excise_rate_applied' => ['nullable', 'numeric', 'min:0'],
            'items.*.excise_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($validated['items'] as $itemData) {
            $itemData['declaration_id'] = $exciseDeclaration->id;
            $exciseDeclaration->items()->create($itemData);
        }

        $exciseDeclaration->recalculateTotals();

        return $this->success(
            $exciseDeclaration->fresh(['items']),
            'Items added to excise declaration successfully'
        );
    }
}

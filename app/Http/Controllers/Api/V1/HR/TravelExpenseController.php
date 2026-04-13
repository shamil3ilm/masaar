<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\PerDiemRate;
use App\Models\HR\TravelExpenseClaim;
use App\Models\HR\TravelRequest;
use App\Services\HR\TravelExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TravelExpenseController extends Controller
{
    public function __construct(
        private TravelExpenseService $service
    ) {}

    // ---------------------------------------------------------------
    // Per Diem Rates
    // ---------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        // Determine context from route prefix to multiplex index
        $prefix = $request->route()->getPrefix() ?? '';

        if (str_contains($prefix, 'per-diem-rates')) {
            return $this->perDiemIndex($request);
        }

        if (str_contains($prefix, 'claims')) {
            return $this->claimsIndex($request);
        }

        return $this->requestsIndex($request);
    }

    private function perDiemIndex(Request $request): JsonResponse
    {
        $rates = PerDiemRate::active()
            ->when($request->country, fn ($q, $c) => $q->where('destination_country', $c))
            ->orderBy('destination_country')
            ->orderBy('destination_city')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($rates);
    }

    public function store(Request $request): JsonResponse
    {
        $prefix = $request->route()->getPrefix() ?? '';

        if (str_contains($prefix, 'per-diem-rates')) {
            return $this->storePerDiemRate($request);
        }

        if (str_contains($prefix, 'claims')) {
            return $this->storeClaim($request);
        }

        return $this->storeRequest($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $prefix = $request->route()->getPrefix() ?? '';

        if (str_contains($prefix, 'per-diem-rates')) {
            $rate = PerDiemRate::findOrFail($id);
            return $this->success($rate);
        }

        if (str_contains($prefix, 'claims')) {
            $claim = TravelExpenseClaim::with(['employee', 'travelRequest', 'lines', 'approver'])->findOrFail($id);
            return $this->success($claim);
        }

        $travelRequest = TravelRequest::with(['employee', 'expenseClaims', 'approver'])->findOrFail($id);
        return $this->success($travelRequest);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $prefix = $request->route()->getPrefix() ?? '';

        if (str_contains($prefix, 'per-diem-rates')) {
            return $this->updatePerDiemRate($request, $id);
        }

        return $this->error('Update not supported for this resource.', 'NOT_SUPPORTED', 405);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $prefix = $request->route()->getPrefix() ?? '';

        if (str_contains($prefix, 'per-diem-rates')) {
            $rate = PerDiemRate::findOrFail($id);
            $rate->delete();
            return $this->success(null, 'Per diem rate deleted.');
        }

        if (str_contains($prefix, 'claims')) {
            $claim = TravelExpenseClaim::findOrFail($id);
            if (!$claim->isDraft()) {
                return $this->error('Only draft claims can be deleted.', 'INVALID_STATE', 422);
            }
            $claim->delete();
            return $this->success(null, 'Claim deleted.');
        }

        $travelRequest = TravelRequest::findOrFail($id);
        if (!$travelRequest->isDraft()) {
            return $this->error('Only draft requests can be deleted.', 'INVALID_STATE', 422);
        }
        $travelRequest->delete();
        return $this->success(null, 'Travel request deleted.');
    }

    // ---------------------------------------------------------------
    // Per Diem Rate helpers
    // ---------------------------------------------------------------

    private function storePerDiemRate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination_country' => 'required|string|size:3',
            'destination_city'    => 'nullable|string|max:100',
            'daily_allowance'     => 'required|numeric|min:0',
            'currency_code'       => 'nullable|string|size:3',
            'meal_allowance_type' => 'nullable|in:included,separate',
            'meal_breakfast'      => 'nullable|numeric|min:0',
            'meal_lunch'          => 'nullable|numeric|min:0',
            'meal_dinner'         => 'nullable|numeric|min:0',
            'mileage_rate'        => 'nullable|numeric|min:0',
            'is_active'           => 'nullable|boolean',
        ]);

        $validated['organization_id'] = $this->organizationId($request);

        try {
            $rate = $this->service->storePerDiemRate($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 'STORE_FAILED', 422);
        }

        return $this->success($rate, 'Per diem rate created.', 201);
    }

    private function updatePerDiemRate(Request $request, int $id): JsonResponse
    {
        $rate = PerDiemRate::findOrFail($id);

        $validated = $request->validate([
            'daily_allowance'     => 'sometimes|required|numeric|min:0',
            'currency_code'       => 'sometimes|required|string|size:3',
            'meal_allowance_type' => 'sometimes|required|in:included,separate',
            'meal_breakfast'      => 'sometimes|nullable|numeric|min:0',
            'meal_lunch'          => 'sometimes|nullable|numeric|min:0',
            'meal_dinner'         => 'sometimes|nullable|numeric|min:0',
            'mileage_rate'        => 'sometimes|nullable|numeric|min:0',
            'is_active'           => 'sometimes|boolean',
        ]);

        $rate->update($validated);

        return $this->success($rate->fresh(), 'Per diem rate updated.');
    }

    public function calculatePerDiem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination_country' => 'required|string|size:3',
            'destination_city'    => 'nullable|string|max:100',
            'days'                => 'required|integer|min:1',
        ]);

        $result = $this->service->calculatePerDiem(
            $this->organizationId($request),
            $validated['destination_country'],
            $validated['destination_city'] ?? null,
            $validated['days']
        );

        return $this->success($result);
    }

    // ---------------------------------------------------------------
    // Travel Requests
    // ---------------------------------------------------------------

    private function requestsIndex(Request $request): JsonResponse
    {
        $query = TravelRequest::with(['employee', 'approver'])
            ->when($request->employee_id, fn ($q, $id) => $q->forEmployee((int) $id))
            ->when($request->status, fn ($q, $s) => $q->byStatus($s))
            ->orderBy(
                $this->safeSortBy($request->sort_by, ['departure_date', 'return_date', 'status', 'created_at'], 'departure_date'),
                $this->safeSortOrder($request->sort_order, 'desc')
            );

        return $this->paginated($query->paginate($request->integer('per_page', 15)));
    }

    private function storeRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'         => 'required|integer|exists:employees,id',
            'purpose'             => 'required|string|max:500',
            'departure_date'      => 'required|date',
            'return_date'         => 'required|date|after_or_equal:departure_date',
            'destination_country' => 'required|string|size:3',
            'destination_city'    => 'nullable|string|max:100',
            'travel_type'         => 'nullable|in:domestic,international',
            'estimated_cost'      => 'nullable|numeric|min:0',
            'advance_requested'   => 'nullable|numeric|min:0',
        ]);

        $validated['organization_id'] = $this->organizationId($request);
        $validated['created_by']      = auth()->id();

        try {
            $travelRequest = $this->service->createRequest($validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success(
            $travelRequest->load(['employee', 'creator']),
            'Travel request created.',
            201
        );
    }

    public function submitRequest(Request $request, TravelRequest $travelRequest): JsonResponse
    {
        return $this->tryAction(
            function () use ($travelRequest) {
                $this->service->submit($travelRequest);
                return $travelRequest->refresh();
            },
            'Travel request submitted.',
            'INVALID_STATE'
        );
    }

    public function approveRequest(Request $request, TravelRequest $travelRequest): JsonResponse
    {
        $validated = $request->validate([
            'advance_approved' => 'nullable|numeric|min:0',
        ]);

        return $this->tryAction(
            function () use ($travelRequest, $validated) {
                $this->service->approve($travelRequest, (float) ($validated['advance_approved'] ?? 0));
                return $travelRequest->refresh();
            },
            'Travel request approved.',
            'INVALID_STATE'
        );
    }

    public function rejectRequest(Request $request, TravelRequest $travelRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        return $this->tryAction(
            function () use ($travelRequest, $validated) {
                $this->service->reject($travelRequest, $validated['reason']);
                return $travelRequest->refresh();
            },
            'Travel request rejected.',
            'INVALID_STATE'
        );
    }

    // ---------------------------------------------------------------
    // Expense Claims
    // ---------------------------------------------------------------

    private function claimsIndex(Request $request): JsonResponse
    {
        $query = TravelExpenseClaim::with(['employee', 'travelRequest', 'approver'])
            ->when($request->employee_id, fn ($q, $id) => $q->forEmployee((int) $id))
            ->when($request->status, fn ($q, $s) => $q->byStatus($s))
            ->orderBy(
                $this->safeSortBy($request->sort_by, ['claim_date', 'status', 'created_at'], 'claim_date'),
                $this->safeSortOrder($request->sort_order, 'desc')
            );

        return $this->paginated($query->paginate($request->integer('per_page', 15)));
    }

    private function storeClaim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'       => 'required|integer|exists:employees,id',
            'travel_request_id' => 'nullable|integer|exists:travel_requests,id',
            'claim_date'        => 'nullable|date',
            'advance_paid'      => 'nullable|numeric|min:0',
        ]);

        $validated['organization_id'] = $this->organizationId($request);
        $validated['created_by']      = auth()->id();

        try {
            $claim = $this->service->createClaim($validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success(
            $claim->load(['employee', 'travelRequest']),
            'Expense claim created.',
            201
        );
    }

    public function addLine(Request $request, TravelExpenseClaim $travelExpenseClaim): JsonResponse
    {
        $validated = $request->validate([
            'expense_date'      => 'required|date',
            'expense_category'  => 'required|in:flight,hotel,meal,transport,per_diem,mileage,visa,other',
            'description'       => 'nullable|string|max:255',
            'amount'            => 'required|numeric|min:0.01',
            'mileage_km'        => 'nullable|numeric|min:0',
            'currency_code'     => 'nullable|string|size:3',
            'exchange_rate'     => 'nullable|numeric|min:0.000001',
            'receipt_reference' => 'nullable|string|max:100',
            'receipt_attached'  => 'nullable|boolean',
        ]);

        try {
            $line = $this->service->addLine($travelExpenseClaim, $validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success($line, 'Expense line added.', 201);
    }

    public function submitClaim(TravelExpenseClaim $travelExpenseClaim): JsonResponse
    {
        return $this->tryAction(
            function () use ($travelExpenseClaim) {
                $this->service->submitClaim($travelExpenseClaim);
                return $travelExpenseClaim->refresh();
            },
            'Claim submitted.',
            'INVALID_STATE'
        );
    }

    public function approveClaim(TravelExpenseClaim $travelExpenseClaim): JsonResponse
    {
        return $this->tryAction(
            function () use ($travelExpenseClaim) {
                $this->service->approveClaim($travelExpenseClaim);
                return $travelExpenseClaim->refresh();
            },
            'Claim approved.',
            'INVALID_STATE'
        );
    }

    public function processClaim(TravelExpenseClaim $travelExpenseClaim): JsonResponse
    {
        return $this->tryAction(
            function () use ($travelExpenseClaim) {
                $this->service->processClaim($travelExpenseClaim);
                return $travelExpenseClaim->refresh();
            },
            'Claim processed for payment.',
            'INVALID_STATE'
        );
    }
}

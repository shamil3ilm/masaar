<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\TimeSheet;
use App\Models\HR\TimeWageType;
use App\Services\HR\TimeEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeEvaluationController extends Controller
{
    public function __construct(
        private TimeEvaluationService $service
    ) {}

    // ---------------------------------------------------------------
    // Time Sheets — CRUD
    // ---------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $query = TimeSheet::with(['employee', 'creator', 'approver'])
            ->when($request->employee_id, fn ($q, $id) => $q->forEmployee((int) $id))
            ->when($request->status, fn ($q, $s) => $q->byStatus($s))
            ->when($request->period_start, fn ($q, $d) => $q->where('period_start', '>=', $d))
            ->when($request->period_end, fn ($q, $d) => $q->where('period_end', '<=', $d))
            ->orderBy(
                $this->safeSortBy($request->sort_by, ['period_start', 'period_end', 'status', 'created_at'], 'period_start'),
                $this->safeSortOrder($request->sort_order, 'desc')
            );

        $sheets = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($sheets);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'  => 'required|integer|exists:employees,id',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $validated['organization_id'] = $this->organizationId($request);
        $validated['created_by']      = auth()->id();

        try {
            $timeSheet = $this->service->createTimeSheet($validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success($timeSheet->load(['employee', 'creator']), 'Time sheet created.', 201);
    }

    public function update(Request $request, TimeSheet $timeSheet): JsonResponse
    {
        if (!$timeSheet->isDraft()) {
            return $this->error('Only draft time sheets can be updated.', 'INVALID_STATE', 422);
        }

        $validated = $request->validate([
            'period_start' => 'sometimes|date',
            'period_end'   => 'sometimes|date|after_or_equal:period_start',
        ]);

        $timeSheet->update($validated);

        return $this->success($timeSheet->fresh(), 'Time sheet updated successfully.');
    }

    public function show(TimeSheet $timeSheet): JsonResponse
    {
        return $this->success(
            $timeSheet->load(['employee', 'creator', 'approver', 'entries.wageType', 'entries.costCenter'])
        );
    }

    public function destroy(TimeSheet $timeSheet): JsonResponse
    {
        if (!$timeSheet->isDraft()) {
            return $this->error('Only draft time sheets can be deleted.', 'INVALID_STATE', 422);
        }

        $timeSheet->delete();

        return $this->success(null, 'Time sheet deleted.');
    }

    // ---------------------------------------------------------------
    // Entries
    // ---------------------------------------------------------------

    public function addEntry(Request $request, TimeSheet $timeSheet): JsonResponse
    {
        $validated = $request->validate([
            'entry_date'     => 'required|date',
            'start_time'     => 'nullable|date_format:H:i',
            'end_time'       => 'nullable|date_format:H:i',
            'hours'          => 'required|numeric|min:0.01|max:24',
            'entry_type'     => 'nullable|in:regular,overtime,absence,holiday,training',
            'wage_type_id'   => 'nullable|integer|exists:time_wage_types,id',
            'cost_center_id' => 'nullable|integer|exists:cost_centers,id',
            'project_id'     => 'nullable|integer',
            'wbs_element_id' => 'nullable|integer',
            'work_order_id'  => 'nullable|integer',
            'activity_code'  => 'nullable|string|max:20',
            'notes'          => 'nullable|string|max:1000',
        ]);

        try {
            $entry = $this->service->addEntry($timeSheet, $validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success($entry->load(['wageType', 'costCenter']), 'Entry added.', 201);
    }

    // ---------------------------------------------------------------
    // Workflow actions
    // ---------------------------------------------------------------

    public function submit(TimeSheet $timeSheet): JsonResponse
    {
        return $this->tryAction(
            function () use ($timeSheet) {
                $this->service->submit($timeSheet);
                return $timeSheet->refresh();
            },
            'Time sheet submitted.',
            'INVALID_STATE'
        );
    }

    public function approve(TimeSheet $timeSheet): JsonResponse
    {
        return $this->tryAction(
            function () use ($timeSheet) {
                $this->service->approve($timeSheet);
                return $timeSheet->refresh();
            },
            'Time sheet approved.',
            'INVALID_STATE'
        );
    }

    public function reject(Request $request, TimeSheet $timeSheet): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        return $this->tryAction(
            function () use ($timeSheet, $validated) {
                $this->service->reject($timeSheet, $validated['reason']);
                return $timeSheet->refresh();
            },
            'Time sheet rejected.',
            'INVALID_STATE'
        );
    }

    // ---------------------------------------------------------------
    // Evaluation
    // ---------------------------------------------------------------

    public function evaluate(TimeSheet $timeSheet): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->service->evaluate($timeSheet),
            'Evaluation completed.',
            'INVALID_STATE'
        );
    }

    public function transferToPayroll(TimeSheet $timeSheet): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->service->transferToPayroll($timeSheet),
            'Transferred to payroll.',
            'INVALID_STATE'
        );
    }

    public function costAllocation(TimeSheet $timeSheet): JsonResponse
    {
        $allocation = $this->service->generateCostAllocation($timeSheet);

        return $this->success($allocation, 'Cost allocation generated.');
    }

    // ---------------------------------------------------------------
    // Wage Types
    // ---------------------------------------------------------------

    public function wageTypes(Request $request): JsonResponse
    {
        $wageTypes = TimeWageType::active()
            ->when($request->category, fn ($q, $c) => $q->byCategory($c))
            ->orderBy('code')
            ->get();

        return $this->success($wageTypes);
    }

    public function storeWageType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'            => 'required|string|max:10',
            'name'            => 'required|string|max:100',
            'wage_category'   => 'required|in:overtime,night_differential,weekend,holiday,absence_deduction,other',
            'rate_multiplier' => 'nullable|numeric|min:0|max:9.9999',
            'is_active'       => 'nullable|boolean',
        ]);

        $validated['organization_id'] = $this->organizationId($request);

        $existing = TimeWageType::where('organization_id', $validated['organization_id'])
            ->where('code', $validated['code'])
            ->first();

        if ($existing !== null) {
            return $this->error('A wage type with this code already exists.', 'DUPLICATE_CODE', 422);
        }

        $wageType = TimeWageType::create($validated);

        return $this->success($wageType, 'Wage type created.', 201);
    }

    public function updateWageType(Request $request, TimeWageType $timeWageType): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'sometimes|required|string|max:100',
            'wage_category'   => 'sometimes|required|in:overtime,night_differential,weekend,holiday,absence_deduction,other',
            'rate_multiplier' => 'sometimes|nullable|numeric|min:0|max:9.9999',
            'is_active'       => 'sometimes|boolean',
        ]);

        $timeWageType->update($validated);

        return $this->success($timeWageType->fresh(), 'Wage type updated.');
    }

    public function destroyWageType(TimeWageType $timeWageType): JsonResponse
    {
        if ($timeWageType->evaluationResults()->exists()) {
            return $this->error(
                'Cannot delete a wage type that has evaluation results.',
                'HAS_REFERENCES',
                422
            );
        }

        $timeWageType->delete();

        return $this->success(null, 'Wage type deleted.');
    }
}

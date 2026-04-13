<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\Core\Organization;
use App\Models\HR\Employee;
use App\Models\HR\GosiContribution;
use App\Services\HR\GosiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GosiController extends Controller
{
    public function __construct(
        private GosiService $gosiService
    ) {}

    /**
     * List GOSI contributions for the authenticated organization.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = auth()->user()->organization_id;

        $contributions = GosiContribution::where('organization_id', $orgId)
            ->with('employee')
            ->when($request->employee_id, fn($q, $v) => $q->where('employee_id', $v))
            ->when($request->year, fn($q, $v) => $q->where('period_year', (int) $v))
            ->when($request->month, fn($q, $v) => $q->where('period_month', (int) $v))
            ->when($request->status, fn($q, $v) => $q->where('status', $v))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($contributions);
    }

    /**
     * Calculate GOSI contributions for an employee for a given period.
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        return $this->tryAction(
            fn() => $this->gosiService->calculateContributions($employee, (int) $validated['year'], (int) $validated['month'])->load('employee'),
            'GOSI contribution calculated successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Submit all draft contributions for an organization for a period.
     */
    public function submitPeriod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $org = Organization::findOrFail(auth()->user()->organization_id);

        $this->gosiService->submitPeriod($org, (int) $validated['year'], (int) $validated['month']);

        return $this->success(null, 'GOSI contributions submitted for the period.');
    }
}

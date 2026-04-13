<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Position;
use App\Services\HR\PositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function __construct(
        private PositionService $positionService
    ) {}

    /**
     * List positions with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['department_id', 'status', 'is_key_position', 'search', 'per_page']);
        $positions = $this->positionService->index($filters);

        return $this->paginated($positions);
    }

    /**
     * Create a new position.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'position_code'          => 'required|string|max:20',
            'position_title'         => 'required|string|max:150',
            'department_id'          => 'nullable|exists:departments,id',
            'designation_id'         => 'nullable|exists:designations,id',
            'pay_grade_id'           => 'nullable|exists:pay_grades,id',
            'reports_to_position_id' => 'nullable|exists:positions,id',
            'headcount_authorized'   => 'nullable|integer|min:1',
            'is_key_position'        => 'nullable|boolean',
        ]);

        try {
            $position = $this->positionService->store($validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success($position->load(['department', 'designation', 'payGrade']), 'Position created.', 201);
    }

    /**
     * Show a single position.
     */
    public function show(Position $position): JsonResponse
    {
        return $this->success(
            $position->load(['department', 'designation', 'payGrade', 'reportsTo', 'subordinates'])
        );
    }

    /**
     * Update a position.
     */
    public function update(Request $request, Position $position): JsonResponse
    {
        $validated = $request->validate([
            'position_code'          => 'sometimes|string|max:20',
            'position_title'         => 'sometimes|string|max:150',
            'department_id'          => 'nullable|exists:departments,id',
            'designation_id'         => 'nullable|exists:designations,id',
            'pay_grade_id'           => 'nullable|exists:pay_grades,id',
            'reports_to_position_id' => 'nullable|exists:positions,id',
            'headcount_authorized'   => 'sometimes|integer|min:1',
            'is_key_position'        => 'sometimes|boolean',
            'status'                 => 'sometimes|in:active,frozen,abolished',
        ]);

        try {
            $updated = $this->positionService->update($position, $validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success($updated->load(['department', 'designation', 'payGrade']), 'Position updated.');
    }

    /**
     * Soft-delete a position.
     */
    public function destroy(Position $position): JsonResponse
    {
        if ($position->headcount_filled > 0) {
            return $this->error(
                'Cannot delete a position that has filled headcount. Vacate all employees first.',
                'POSITION_OCCUPIED',
                422
            );
        }

        $position->delete();

        return $this->success(null, 'Position deleted.');
    }

    /**
     * Return nested position hierarchy for the current organization.
     */
    public function hierarchy(Request $request): JsonResponse
    {
        $orgId     = $this->organizationId($request);
        $hierarchy = $this->positionService->getHierarchy($orgId);

        return $this->success($hierarchy);
    }

    /**
     * Assign an employee to a position.
     */
    public function assignEmployee(Request $request, Position $position): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
        ]);

        return $this->tryAction(
            function () use ($position, $validated) {
                $this->positionService->assignEmployee($position, $validated['employee_id']);
                return null;
            },
            'Employee assigned to position.',
            'ASSIGNMENT_ERROR'
        );
    }

    /**
     * Vacate an employee from a position.
     */
    public function vacatePosition(Request $request, Position $position): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
        ]);

        return $this->tryAction(
            function () use ($position, $validated) {
                $this->positionService->vacatePosition($position, $validated['employee_id']);
                return null;
            },
            'Employee vacated from position.',
            'VACATE_ERROR'
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Http\Resources\HR\AttendanceResource;
use App\Models\HR\Attendance;
use App\Models\HR\Employee;
use App\Services\HR\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceService $attendanceService
    ) {}

    /**
     * List attendance records with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Attendance::with(['employee', 'workSchedule'])
            ->when($request->employee_id, fn($q, $id) => $q->forEmployee($id))
            ->when($request->status, fn($q, $status) => $q->withStatus($status))
            ->when($request->date, fn($q, $date) => $q->forDate($date))
            ->when($request->start_date && $request->end_date, fn($q) =>
                $q->inDateRange($request->start_date, $request->end_date)
            )
            ->when($request->late === 'true', fn($q) => $q->late())
            ->orderBy('attendance_date', 'desc')
            ->orderBy('employee_id');

        $attendances = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return AttendanceResource::collection($attendances);
    }

    /**
     * Record check-in.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'check_in_time' => 'nullable|date',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'device_id' => 'nullable|string|max:100',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $attendance = $this->attendanceService->checkIn(
            $employee,
            isset($validated['check_in_time']) ? new \DateTime($validated['check_in_time']) : null,
            Attendance::SOURCE_MANUAL,
            $validated['latitude'] ?? null,
            $validated['longitude'] ?? null,
            $validated['device_id'] ?? null
        );

        return response()->json([
            'message' => 'Check-in recorded successfully.',
            'data' => new AttendanceResource($attendance),
        ]);
    }

    /**
     * Record check-out.
     */
    public function checkOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'check_out_time' => 'nullable|date',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $attendance = $this->attendanceService->checkOut(
            $employee,
            isset($validated['check_out_time']) ? new \DateTime($validated['check_out_time']) : null,
            $validated['latitude'] ?? null,
            $validated['longitude'] ?? null
        );

        return response()->json([
            'message' => 'Check-out recorded successfully.',
            'data' => new AttendanceResource($attendance),
        ]);
    }

    /**
     * Mark attendance manually.
     */
    public function mark(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'status' => 'required|in:present,absent,half_day,on_leave,holiday,weekend,work_from_home,on_duty',
            'check_in' => 'nullable|date',
            'check_out' => 'nullable|date|after:check_in',
            'notes' => 'nullable|string|max:500',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $attendance = $this->attendanceService->markAttendance(
            $employee,
            new \DateTime($validated['date']),
            $validated['status'],
            isset($validated['check_in']) ? new \DateTime($validated['check_in']) : null,
            isset($validated['check_out']) ? new \DateTime($validated['check_out']) : null,
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => 'Attendance marked successfully.',
            'data' => new AttendanceResource($attendance),
        ]);
    }

    /**
     * Generate attendance records.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $count = $this->attendanceService->generateAttendance(
            new \DateTime($validated['start_date']),
            new \DateTime($validated['end_date'])
        );

        return response()->json([
            'message' => "Generated {$count} attendance records.",
        ]);
    }

    /**
     * Get employee attendance summary.
     */
    public function employeeSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $summary = $this->attendanceService->getEmployeeSummary(
            $employee,
            new \DateTime($validated['start_date']),
            new \DateTime($validated['end_date'])
        );

        return response()->json(['data' => $summary]);
    }

    /**
     * Get today's attendance status.
     */
    public function todayStatus(): JsonResponse
    {
        $status = $this->attendanceService->getTodayStatus();

        return response()->json(['data' => $status]);
    }
}

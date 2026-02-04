<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Services\HR\HRDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HRDashboardController extends Controller
{
    public function __construct(
        protected HRDashboardService $dashboardService
    ) {}

    /**
     * Get all dashboard widgets data.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->dashboardService->getAllWidgets();

        return response()->json(['data' => $data]);
    }

    /**
     * Get summary widget.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getSummaryWidget(),
        ]);
    }

    /**
     * Get headcount by department.
     */
    public function headcountByDepartment(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getHeadcountByDepartment(),
        ]);
    }

    /**
     * Get headcount by status.
     */
    public function headcountByStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getHeadcountByStatus(),
        ]);
    }

    /**
     * Get today's attendance widget.
     */
    public function attendanceToday(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getAttendanceTodayWidget(),
        ]);
    }

    /**
     * Get attendance trend.
     */
    public function attendanceTrend(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = $request->get('days', 7);

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getAttendanceTrend((int) $days),
        ]);
    }

    /**
     * Get leave summary widget.
     */
    public function leaveSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getLeaveSummaryWidget(),
        ]);
    }

    /**
     * Get pending approvals widget.
     */
    public function pendingApprovals(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getPendingApprovalsWidget(),
        ]);
    }

    /**
     * Get payroll summary widget.
     */
    public function payrollSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getPayrollSummaryWidget(),
        ]);
    }

    /**
     * Get upcoming birthdays.
     */
    public function birthdays(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = $request->get('days', 30);

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getUpcomingBirthdays((int) $days),
        ]);
    }

    /**
     * Get work anniversaries.
     */
    public function anniversaries(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = $request->get('days', 30);

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getWorkAnniversaries((int) $days),
        ]);
    }

    /**
     * Get document expiry alerts.
     */
    public function documentAlerts(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = $request->get('days', 30);

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getDocumentExpiryAlerts((int) $days),
        ]);
    }

    /**
     * Get new joiners.
     */
    public function newJoiners(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = $request->get('days', 30);

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getNewJoiners((int) $days),
        ]);
    }

    /**
     * Get recent exits.
     */
    public function recentExits(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = $request->get('days', 30);

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getRecentExits((int) $days),
        ]);
    }

    /**
     * Get demographics data.
     */
    public function demographics(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => [
                'gender' => $this->dashboardService->getGenderDistribution(),
                'age' => $this->dashboardService->getAgeDistribution(),
                'tenure' => $this->dashboardService->getTenureDistribution(),
            ],
        ]);
    }
}

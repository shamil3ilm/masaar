<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    /**
     * Get comprehensive dashboard statistics.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : now()->startOfMonth();

        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : now()->endOfMonth();

        $stats = $this->dashboardService->getDashboardStats($startDate, $endDate);

        return response()->json(['data' => $stats]);
    }

    /**
     * Get sales dashboard.
     */
    public function sales(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : now()->startOfMonth();

        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : now()->endOfMonth();

        $stats = $this->dashboardService->getSalesStats($startDate, $endDate);

        return response()->json(['data' => $stats]);
    }

    /**
     * Get purchase dashboard.
     */
    public function purchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : now()->startOfMonth();

        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : now()->endOfMonth();

        $stats = $this->dashboardService->getPurchaseStats($startDate, $endDate);

        return response()->json(['data' => $stats]);
    }

    /**
     * Get inventory dashboard.
     */
    public function inventory(): JsonResponse
    {
        $stats = $this->dashboardService->getInventoryStats();

        return response()->json(['data' => $stats]);
    }

    /**
     * Get HR dashboard.
     */
    public function hr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : now()->startOfMonth();

        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : now()->endOfMonth();

        $stats = $this->dashboardService->getHrStats($startDate, $endDate);

        return response()->json(['data' => $stats]);
    }

    /**
     * Get CRM dashboard.
     */
    public function crm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : now()->startOfMonth();

        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : now()->endOfMonth();

        $stats = $this->dashboardService->getCrmStats($startDate, $endDate);

        return response()->json(['data' => $stats]);
    }

    /**
     * Get manufacturing dashboard.
     */
    public function manufacturing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : now()->startOfMonth();

        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : now()->endOfMonth();

        $stats = $this->dashboardService->getManufacturingStats($startDate, $endDate);

        return response()->json(['data' => $stats]);
    }

    /**
     * Get recent activity.
     */
    public function recentActivity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:5|max:50',
        ]);

        $activity = $this->dashboardService->getRecentActivity($validated['limit'] ?? 20);

        return response()->json(['data' => $activity]);
    }

    /**
     * Get system alerts.
     */
    public function alerts(): JsonResponse
    {
        $alerts = $this->dashboardService->getAlerts();

        return response()->json(['data' => $alerts]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Models\Reports\ReportExecution;
use App\Models\Reports\SavedReport;
use App\Services\Reports\FinancialReportService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\SalesReportService;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportsController extends Controller
{
    public function __construct(
        protected FinancialReportService $financialService,
        protected InventoryReportService $inventoryService,
        protected SalesReportService $salesService,
        protected ReportExportService $exportService
    ) {}

    /**
     * Get available report types.
     */
    public function types(): JsonResponse
    {
        return response()->json([
            'data' => SavedReport::getReportTypes(),
            'schedules' => SavedReport::getScheduleOptions(),
            'formats' => SavedReport::getExportFormats(),
        ]);
    }

    // ==========================================
    // Financial Reports
    // ==========================================

    /**
     * Get Balance Sheet.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'as_of_date' => 'required|date',
            'compare_to' => 'nullable|date',
            'fiscal_year_id' => 'nullable|integer|exists:fiscal_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $data = $this->financialService->getBalanceSheet(
            Carbon::parse($request->get('as_of_date'))
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Income Statement (P&L).
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $this->financialService->getProfitAndLoss(
            Carbon::parse($request->get('start_date')),
            Carbon::parse($request->get('end_date'))
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Trial Balance.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'as_of_date' => 'required|date',
            'show_zero_balances' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $this->financialService->getTrialBalance(
            Carbon::parse($request->get('as_of_date'))
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Cash Flow Statement.
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $this->financialService->getCashFlow(
            Carbon::parse($request->get('start_date')),
            Carbon::parse($request->get('end_date'))
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Aged Receivables.
     */
    public function agedReceivables(Request $request): JsonResponse
    {
        $data = $this->financialService->getReceivableAging();

        return response()->json(['data' => $data]);
    }

    /**
     * Get Aged Payables.
     */
    public function agedPayables(Request $request): JsonResponse
    {
        $data = $this->financialService->getPayableAging();

        return response()->json(['data' => $data]);
    }

    // ==========================================
    // Inventory Reports
    // ==========================================

    /**
     * Get Stock Valuation Report.
     */
    public function stockValuation(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->inventoryService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->inventoryService->generateStockValuation(
            $request->get('warehouse_id'),
            $request->get('category_id'),
            $request->get('valuation_method')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Stock Movement Report.
     */
    public function stockMovement(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'product_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'movement_type' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->inventoryService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->inventoryService->generateStockMovement(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('product_id'),
            $request->get('warehouse_id'),
            $request->get('movement_type')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Low Stock Report.
     */
    public function lowStock(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->inventoryService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->inventoryService->generateLowStockReport(
            $request->get('warehouse_id')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Inventory Turnover Report.
     */
    public function inventoryTurnover(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->inventoryService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->inventoryService->generateInventoryTurnover(
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Batch Expiry Report.
     */
    public function batchExpiry(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->inventoryService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->inventoryService->generateExpiryReport(
            $request->get('days_ahead', 90),
            $request->get('warehouse_id')
        );

        return response()->json(['data' => $data]);
    }

    // ==========================================
    // Sales Reports
    // ==========================================

    /**
     * Get Sales by Customer Report.
     */
    public function salesByCustomer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'customer_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->salesService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->salesService->generateSalesByCustomer(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('customer_id'),
            $request->get('limit', 50)
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Sales by Product Report.
     */
    public function salesByProduct(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'category_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->salesService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->salesService->generateSalesByProduct(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('category_id'),
            $request->get('product_id'),
            $request->get('limit', 50)
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Sales by Salesperson Report.
     */
    public function salesBySalesperson(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->salesService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->salesService->generateSalesBySalesperson(
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Sales Trend Report.
     */
    public function salesTrend(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'group_by' => 'nullable|string|in:day,week,month',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->salesService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->salesService->generateSalesTrend(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('group_by', 'day')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Sales Summary Dashboard.
     */
    public function salesSummary(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->salesService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->salesService->generateSalesSummary(
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json(['data' => $data]);
    }

    // ==========================================
    // Export
    // ==========================================

    /**
     * Export report.
     */
    public function export(Request $request): JsonResponse|BinaryFileResponse
    {
        $validator = Validator::make($request->all(), [
            'report_type' => 'required|string',
            'format' => 'required|string|in:pdf,xlsx,csv,json',
            'parameters' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $reportType = $request->get('report_type');
        $format = $request->get('format');
        $parameters = $request->get('parameters');

        // Generate report data
        $data = $this->generateReportData($reportType, $parameters, $user);

        if (isset($data['error'])) {
            return response()->json(['error' => $data['error']], 400);
        }

        // Create execution record
        $execution = ReportExecution::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'report_type' => $reportType,
            'parameters' => $parameters,
            'status' => 'pending',
        ]);

        // Get organization data for export
        $organization = \App\Models\Core\Organization::find($user->organization_id);
        $orgData = $organization ? $organization->toArray() : [];

        // Export
        $this->exportService->setContext($user->organization_id, $orgData);

        try {
            $filePath = $this->exportService->export($reportType, $data, $format, $execution);

            if ($request->boolean('download')) {
                return response()->download(
                    storage_path('app/' . $filePath),
                    basename($filePath)
                )->deleteFileAfterSend(false);
            }

            return response()->json([
                'data' => [
                    'execution_id' => $execution->id,
                    'file_path' => $filePath,
                    'download_url' => route('api.v1.reports.download', $execution->id),
                    'expires_at' => $execution->expires_at?->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download exported report.
     */
    public function download(Request $request, int $executionId): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();

        $execution = ReportExecution::where('organization_id', $user->organization_id)
            ->findOrFail($executionId);

        if (!$execution->isFileAvailable()) {
            return response()->json(['error' => 'File not available or expired'], 404);
        }

        return response()->download(
            storage_path('app/' . $execution->file_path),
            basename($execution->file_path)
        );
    }

    // ==========================================
    // Saved Reports
    // ==========================================

    /**
     * List saved reports.
     */
    public function savedReports(Request $request): JsonResponse
    {
        $user = $request->user();

        $reports = SavedReport::where('organization_id', $user->organization_id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('is_public', true);
            })
            ->with('latestExecution')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $reports]);
    }

    /**
     * Create saved report.
     */
    public function createSavedReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'report_type' => 'required|string',
            'parameters' => 'nullable|array',
            'columns' => 'nullable|array',
            'schedule_frequency' => 'nullable|string|in:daily,weekly,monthly,quarterly',
            'schedule_day' => 'nullable|string',
            'schedule_time' => 'nullable|date_format:H:i',
            'recipients' => 'nullable|array',
            'recipients.*' => 'email',
            'export_format' => 'nullable|string|in:pdf,xlsx,csv,json',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $report = SavedReport::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            ...$validator->validated(),
        ]);

        if ($report->schedule_frequency) {
            $report->next_run_at = $report->calculateNextRunAt();
            $report->save();
        }

        return response()->json(['data' => $report], 201);
    }

    /**
     * Update saved report.
     */
    public function updateSavedReport(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $report = SavedReport::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'parameters' => 'nullable|array',
            'columns' => 'nullable|array',
            'schedule_frequency' => 'nullable|string|in:daily,weekly,monthly,quarterly',
            'schedule_day' => 'nullable|string',
            'schedule_time' => 'nullable|date_format:H:i',
            'recipients' => 'nullable|array',
            'export_format' => 'nullable|string|in:pdf,xlsx,csv,json',
            'is_public' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report->update($validator->validated());

        if ($report->wasChanged('schedule_frequency') || $report->wasChanged('schedule_day')) {
            $report->next_run_at = $report->calculateNextRunAt();
            $report->save();
        }

        return response()->json(['data' => $report]);
    }

    /**
     * Delete saved report.
     */
    public function deleteSavedReport(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $report = SavedReport::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->findOrFail($id);

        $report->delete();

        return response()->json(['message' => 'Report deleted']);
    }

    /**
     * Run saved report.
     */
    public function runSavedReport(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $report = SavedReport::where('organization_id', $user->organization_id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('is_public', true);
            })
            ->findOrFail($id);

        $data = $this->generateReportData(
            $report->report_type,
            $report->parameters ?? [],
            $user
        );

        // Update last run
        $report->update(['last_run_at' => now()]);

        return response()->json(['data' => $data]);
    }

    /**
     * Get report execution history.
     */
    public function executionHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        $executions = ReportExecution::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->with('savedReport:id,name')
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json($executions);
    }

    /**
     * Generate report data based on type.
     */
    protected function generateReportData(string $reportType, array $parameters, $user): array
    {
        // Set context for all services
        $this->inventoryService->setContext($user->organization_id, $user->current_branch_id);
        $this->salesService->setContext($user->organization_id, $user->current_branch_id);

        return match ($reportType) {
            'balance_sheet' => $this->financialService->getBalanceSheet(
                Carbon::parse($parameters['as_of_date'] ?? now())
            ),
            'income_statement', 'profit_loss' => $this->financialService->getProfitAndLoss(
                Carbon::parse($parameters['start_date'] ?? now()->startOfMonth()),
                Carbon::parse($parameters['end_date'] ?? now())
            ),
            'trial_balance' => $this->financialService->getTrialBalance(
                Carbon::parse($parameters['as_of_date'] ?? now())
            ),
            'cash_flow' => $this->financialService->getCashFlow(
                Carbon::parse($parameters['start_date'] ?? now()->startOfMonth()),
                Carbon::parse($parameters['end_date'] ?? now())
            ),
            'aged_receivables' => $this->financialService->getReceivableAging(),
            'aged_payables' => $this->financialService->getPayableAging(),
            'stock_valuation' => $this->inventoryService->generateStockValuation(
                $parameters['warehouse_id'] ?? null,
                $parameters['category_id'] ?? null,
                $parameters['valuation_method'] ?? null
            ),
            'stock_movement' => $this->inventoryService->generateStockMovement(
                $parameters['start_date'] ?? now()->startOfMonth()->format('Y-m-d'),
                $parameters['end_date'] ?? now()->format('Y-m-d'),
                $parameters['product_id'] ?? null,
                $parameters['warehouse_id'] ?? null,
                $parameters['movement_type'] ?? null
            ),
            'low_stock' => $this->inventoryService->generateLowStockReport(
                $parameters['warehouse_id'] ?? null
            ),
            'inventory_turnover' => $this->inventoryService->generateInventoryTurnover(
                $parameters['start_date'] ?? now()->startOfMonth()->format('Y-m-d'),
                $parameters['end_date'] ?? now()->format('Y-m-d')
            ),
            'batch_expiry' => $this->inventoryService->generateExpiryReport(
                $parameters['days_ahead'] ?? 90,
                $parameters['warehouse_id'] ?? null
            ),
            'sales_by_customer' => $this->salesService->generateSalesByCustomer(
                $parameters['start_date'] ?? now()->startOfMonth()->format('Y-m-d'),
                $parameters['end_date'] ?? now()->format('Y-m-d'),
                $parameters['customer_id'] ?? null,
                $parameters['limit'] ?? 50
            ),
            'sales_by_product' => $this->salesService->generateSalesByProduct(
                $parameters['start_date'] ?? now()->startOfMonth()->format('Y-m-d'),
                $parameters['end_date'] ?? now()->format('Y-m-d'),
                $parameters['category_id'] ?? null,
                $parameters['product_id'] ?? null,
                $parameters['limit'] ?? 50
            ),
            'sales_by_salesperson' => $this->salesService->generateSalesBySalesperson(
                $parameters['start_date'] ?? now()->startOfMonth()->format('Y-m-d'),
                $parameters['end_date'] ?? now()->format('Y-m-d')
            ),
            'sales_trend' => $this->salesService->generateSalesTrend(
                $parameters['start_date'] ?? now()->startOfMonth()->format('Y-m-d'),
                $parameters['end_date'] ?? now()->format('Y-m-d'),
                $parameters['group_by'] ?? 'day'
            ),
            'sales_summary' => $this->salesService->generateSalesSummary(
                $parameters['start_date'] ?? now()->startOfMonth()->format('Y-m-d'),
                $parameters['end_date'] ?? now()->format('Y-m-d')
            ),
            default => ['error' => "Unknown report type: {$reportType}"],
        };
    }
}

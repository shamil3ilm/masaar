<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\DashboardLayout;
use App\Models\Core\DashboardWidget;
use App\Models\CRM\Lead;
use App\Models\CRM\Opportunity;
use App\Models\HR\Attendance;
use App\Models\HR\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\InventoryBatch;
use App\Models\Manufacturing\WorkOrder;
use App\Models\Sales\Invoice;
use App\Models\Sales\PaymentReceived;
use App\Models\Core\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    protected int $organizationId;
    protected ?int $branchId = null;

    public function setContext(int $organizationId, ?int $branchId = null): self
    {
        $this->organizationId = $organizationId;
        $this->branchId = $branchId;
        return $this;
    }

    /**
     * Get dashboard data for a layout.
     */
    public function getDashboardData(DashboardLayout $layout): array
    {
        $widgetData = [];

        foreach ($layout->widgets as $widgetConfig) {
            $widget = DashboardWidget::getByCode($widgetConfig['code']);

            if (!$widget || !$widget->is_active) {
                continue;
            }

            $config = array_merge($widget->default_config ?? [], $widgetConfig['config'] ?? []);

            $widgetData[$widgetConfig['code']] = [
                'widget' => $widget->toArray(),
                'config' => $config,
                'data' => $this->getWidgetData($widget, $config),
                'position' => $widgetConfig['position'],
                'size' => $widgetConfig['size'],
            ];
        }

        return [
            'layout' => $layout->toArray(),
            'widgets' => $widgetData,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get data for a specific widget.
     */
    public function getWidgetData(DashboardWidget $widget, array $config = []): array
    {
        $method = $this->parseDataSource($widget->data_source);

        if ($method && method_exists($this, $method)) {
            return $this->$method($config);
        }

        return ['error' => 'Data source not found'];
    }

    protected function parseDataSource(?string $dataSource): ?string
    {
        if (!$dataSource) {
            return null;
        }

        // Format: ClassName@methodName or just methodName
        if (str_contains($dataSource, '@')) {
            $parts = explode('@', $dataSource);
            return $parts[1] ?? null;
        }

        return $dataSource;
    }

    // ==================== Sales Widgets ====================

    public function getTodaySales(array $config = []): array
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        $todaySales = Invoice::where('organization_id', $this->organizationId)
            ->whereDate('invoice_date', $today)
            ->whereNotIn('status', ['draft', 'voided'])
            ->sum('total');

        $yesterdaySales = Invoice::where('organization_id', $this->organizationId)
            ->whereDate('invoice_date', $yesterday)
            ->whereNotIn('status', ['draft', 'voided'])
            ->sum('total');

        $change = $yesterdaySales > 0
            ? (($todaySales - $yesterdaySales) / $yesterdaySales) * 100
            : ($todaySales > 0 ? 100 : 0);

        return [
            'value' => (float) $todaySales,
            'previous_value' => (float) $yesterdaySales,
            'change_percentage' => round($change, 1),
            'trend' => $change >= 0 ? 'up' : 'down',
            'label' => 'Today\'s Sales',
            'comparison_label' => 'vs Yesterday',
        ];
    }

    public function getMonthlySales(array $config = []): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfLastMonth = Carbon::now()->subMonth()->endOfMonth();

        $thisMonth = Invoice::where('organization_id', $this->organizationId)
            ->where('invoice_date', '>=', $startOfMonth)
            ->whereNotIn('status', ['draft', 'voided'])
            ->sum('total');

        $lastMonth = Invoice::where('organization_id', $this->organizationId)
            ->whereBetween('invoice_date', [$startOfLastMonth, $endOfLastMonth])
            ->whereNotIn('status', ['draft', 'voided'])
            ->sum('total');

        $change = $lastMonth > 0
            ? (($thisMonth - $lastMonth) / $lastMonth) * 100
            : ($thisMonth > 0 ? 100 : 0);

        return [
            'value' => (float) $thisMonth,
            'previous_value' => (float) $lastMonth,
            'change_percentage' => round($change, 1),
            'trend' => $change >= 0 ? 'up' : 'down',
            'label' => 'Monthly Sales',
            'comparison_label' => 'vs Last Month',
        ];
    }

    public function getPendingInvoices(array $config = []): array
    {
        $pending = Invoice::where('organization_id', $this->organizationId)
            ->whereIn('status', ['sent', 'partial'])
            ->count();

        $overdue = Invoice::where('organization_id', $this->organizationId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->where('due_date', '<', Carbon::today())
            ->count();

        return [
            'value' => $pending,
            'overdue_count' => $overdue,
            'label' => 'Pending Invoices',
        ];
    }

    public function getOutstandingReceivables(array $config = []): array
    {
        $total = Invoice::where('organization_id', $this->organizationId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('amount_due');

        $overdue = Invoice::where('organization_id', $this->organizationId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->where('due_date', '<', Carbon::today())
            ->sum('amount_due');

        return [
            'value' => (float) $total,
            'overdue_amount' => (float) $overdue,
            'label' => 'Outstanding Receivables',
            'show_overdue' => $config['show_overdue'] ?? true,
        ];
    }

    public function getSalesTrend(array $config = []): array
    {
        $period = $config['period'] ?? '30days';
        $groupBy = $config['group_by'] ?? 'day';

        $startDate = match ($period) {
            '7days' => Carbon::now()->subDays(7),
            '30days' => Carbon::now()->subDays(30),
            '90days' => Carbon::now()->subDays(90),
            '12months' => Carbon::now()->subMonths(12),
            default => Carbon::now()->subDays(30),
        };

        $dateFormat = match ($groupBy) {
            'day' => '%Y-%m-%d',
            'week' => '%Y-%W',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $data = Invoice::where('organization_id', $this->organizationId)
            ->where('invoice_date', '>=', $startDate)
            ->whereNotIn('status', ['draft', 'voided'])
            ->select(
                DB::raw("DATE_FORMAT(invoice_date, '{$dateFormat}') as period"),
                DB::raw('SUM(total) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return [
            'labels' => $data->pluck('period')->toArray(),
            'datasets' => [
                [
                    'label' => 'Sales',
                    'data' => $data->pluck('total')->map(fn($v) => (float)$v)->toArray(),
                ],
            ],
            'summary' => [
                'total' => $data->sum('total'),
                'count' => $data->sum('count'),
                'average' => $data->count() > 0 ? $data->sum('total') / $data->count() : 0,
            ],
        ];
    }

    public function getTopProducts(array $config = []): array
    {
        $limit = $config['limit'] ?? 10;
        $period = $config['period'] ?? 'month';

        $startDate = match ($period) {
            'week' => Carbon::now()->subWeek(),
            'month' => Carbon::now()->subMonth(),
            'quarter' => Carbon::now()->subQuarter(),
            'year' => Carbon::now()->subYear(),
            default => Carbon::now()->subMonth(),
        };

        $products = DB::table('document_lines')
            ->join('invoices', 'document_lines.document_id', '=', 'invoices.id')
            ->join('products', 'document_lines.product_id', '=', 'products.id')
            ->where('document_lines.document_type', 'App\\Models\\Sales\\Invoice')
            ->where('invoices.organization_id', $this->organizationId)
            ->where('invoices.invoice_date', '>=', $startDate)
            ->whereNotIn('invoices.status', ['draft', 'voided'])
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(document_lines.quantity) as total_quantity'),
                DB::raw('SUM(document_lines.total) as total_amount')
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_amount')
            ->limit($limit)
            ->get();

        return [
            'columns' => ['Product', 'SKU', 'Qty Sold', 'Revenue'],
            'rows' => $products->map(fn($p) => [
                'name' => $p->name,
                'sku' => $p->sku,
                'quantity' => (float) $p->total_quantity,
                'revenue' => (float) $p->total_amount,
            ])->toArray(),
        ];
    }

    public function getTopCustomers(array $config = []): array
    {
        $limit = $config['limit'] ?? 10;
        $period = $config['period'] ?? 'month';

        $startDate = match ($period) {
            'week' => Carbon::now()->subWeek(),
            'month' => Carbon::now()->subMonth(),
            'quarter' => Carbon::now()->subQuarter(),
            'year' => Carbon::now()->subYear(),
            default => Carbon::now()->subMonth(),
        };

        $customers = Invoice::where('organization_id', $this->organizationId)
            ->where('invoice_date', '>=', $startDate)
            ->whereNotIn('status', ['draft', 'voided'])
            ->select(
                'customer_id',
                'customer_name',
                DB::raw('SUM(total) as total_amount'),
                DB::raw('COUNT(*) as invoice_count')
            )
            ->groupBy('customer_id', 'customer_name')
            ->orderByDesc('total_amount')
            ->limit($limit)
            ->get();

        return [
            'columns' => ['Customer', 'Orders', 'Revenue'],
            'rows' => $customers->map(fn($c) => [
                'name' => $c->customer_name,
                'orders' => $c->invoice_count,
                'revenue' => (float) $c->total_amount,
            ])->toArray(),
        ];
    }

    public function getSalesByCategory(array $config = []): array
    {
        $period = $config['period'] ?? 'month';
        $startDate = Carbon::now()->subMonth();

        $data = DB::table('document_lines')
            ->join('invoices', 'document_lines.document_id', '=', 'invoices.id')
            ->join('products', 'document_lines.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('document_lines.document_type', 'App\\Models\\Sales\\Invoice')
            ->where('invoices.organization_id', $this->organizationId)
            ->where('invoices.invoice_date', '>=', $startDate)
            ->whereNotIn('invoices.status', ['draft', 'voided'])
            ->select(
                DB::raw('COALESCE(categories.name, \'Uncategorized\') as category'),
                DB::raw('SUM(document_lines.total) as total')
            )
            ->groupBy('categories.name')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $data->pluck('category')->toArray(),
            'data' => $data->pluck('total')->map(fn($v) => (float)$v)->toArray(),
        ];
    }

    // ==================== Inventory Widgets ====================

    public function getLowStockCount(array $config = []): array
    {
        $count = StockLevel::where('organization_id', $this->organizationId)
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->where('reorder_level', '>', 0)
            ->count();

        return [
            'value' => $count,
            'label' => 'Low Stock Items',
            'severity' => $count > 10 ? 'danger' : ($count > 5 ? 'warning' : 'normal'),
        ];
    }

    public function getInventoryValue(array $config = []): array
    {
        $value = StockLevel::where('organization_id', $this->organizationId)
            ->selectRaw('SUM(quantity * average_cost) as total_value')
            ->value('total_value') ?? 0;

        return [
            'value' => (float) $value,
            'label' => 'Inventory Value',
        ];
    }

    public function getExpiringProducts(array $config = []): array
    {
        $days = $config['days'] ?? 30;

        $expiring = InventoryBatch::where('organization_id', $this->organizationId)
            ->where('status', 'available')
            ->where('expiry_date', '<=', Carbon::now()->addDays($days))
            ->where('expiry_date', '>', Carbon::now())
            ->with('product:id,name,sku')
            ->orderBy('expiry_date')
            ->limit(10)
            ->get();

        return [
            'items' => $expiring->map(fn($b) => [
                'product' => $b->product->name ?? 'Unknown',
                'sku' => $b->product->sku ?? '',
                'batch' => $b->batch_number,
                'expiry_date' => $b->expiry_date->format('Y-m-d'),
                'days_until_expiry' => $b->expiry_date->diffInDays(Carbon::now()),
                'quantity' => (float) $b->quantity,
            ])->toArray(),
            'total_count' => InventoryBatch::where('organization_id', $this->organizationId)
                ->where('status', 'available')
                ->where('expiry_date', '<=', Carbon::now()->addDays($days))
                ->where('expiry_date', '>', Carbon::now())
                ->count(),
        ];
    }

    public function getStockMovement(array $config = []): array
    {
        // Simplified - would need StockMovement model data
        return [
            'labels' => ['In', 'Out', 'Adjustments'],
            'data' => [0, 0, 0],
            'message' => 'Stock movement tracking not yet implemented',
        ];
    }

    // ==================== Finance Widgets ====================

    public function getCashBalance(array $config = []): array
    {
        // This would need BankAccount/JournalEntry data
        return [
            'value' => 0,
            'label' => 'Cash Balance',
            'message' => 'Connect bank accounts to see balance',
        ];
    }

    public function getProfitLossSummary(array $config = []): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        $revenue = Invoice::where('organization_id', $this->organizationId)
            ->where('invoice_date', '>=', $startOfMonth)
            ->whereNotIn('status', ['draft', 'voided'])
            ->sum('total');

        // Expenses would come from Bills/Purchases
        $expenses = 0;

        return [
            'revenue' => (float) $revenue,
            'expenses' => (float) $expenses,
            'net_profit' => (float) ($revenue - $expenses),
            'label' => 'P&L Summary (MTD)',
        ];
    }

    public function getRevenueVsExpense(array $config = []): array
    {
        $months = [];
        $revenue = [];
        $expenses = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $months[] = $date->format('M Y');

            $monthRevenue = Invoice::where('organization_id', $this->organizationId)
                ->whereYear('invoice_date', $date->year)
                ->whereMonth('invoice_date', $date->month)
                ->whereNotIn('status', ['draft', 'voided'])
                ->sum('total');

            $revenue[] = (float) $monthRevenue;
            $expenses[] = 0; // Would need expense data
        }

        return [
            'labels' => $months,
            'datasets' => [
                ['label' => 'Revenue', 'data' => $revenue],
                ['label' => 'Expenses', 'data' => $expenses],
            ],
        ];
    }

    public function getOutstandingPayables(array $config = []): array
    {
        // Would need Bill model
        return [
            'value' => 0,
            'label' => 'Outstanding Payables',
        ];
    }

    // ==================== Activity Widgets ====================

    public function getRecentInvoices(array $config = []): array
    {
        $limit = $config['limit'] ?? 10;

        $invoices = Invoice::where('organization_id', $this->organizationId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'invoice_number', 'customer_name', 'total', 'status', 'invoice_date']);

        return [
            'items' => $invoices->map(fn($i) => [
                'id' => $i->id,
                'number' => $i->invoice_number,
                'customer' => $i->customer_name,
                'amount' => (float) $i->total,
                'status' => $i->status,
                'date' => $i->invoice_date->format('Y-m-d'),
            ])->toArray(),
        ];
    }

    public function getActivityTimeline(array $config = []): array
    {
        $limit = $config['limit'] ?? 20;

        $activities = Activity::where('organization_id', $this->organizationId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->with('user:id,name')
            ->get();

        return [
            'items' => $activities->map(fn($a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->getFormattedDescription(),
                'user' => $a->user?->name ?? 'System',
                'icon' => $a->getIcon(),
                'color' => $a->getColor(),
                'time' => $a->created_at->diffForHumans(),
            ])->toArray(),
        ];
    }

    // ==================== Premium Widgets ====================

    public function getSalesForecast(array $config = []): array
    {
        // Premium feature - simple linear forecast
        return [
            'message' => 'Sales forecast - Premium feature',
            'labels' => [],
            'datasets' => [],
        ];
    }

    public function getCustomerAnalytics(array $config = []): array
    {
        return [
            'message' => 'Customer analytics - Premium feature',
        ];
    }

    public function getRegionalSales(array $config = []): array
    {
        return [
            'message' => 'Regional sales map - Premium feature',
        ];
    }

    // ==================== HR Widgets ====================

    public function getEmployeeSummary(array $config = []): array
    {
        $query = Employee::where('organization_id', $this->organizationId);

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $total = (clone $query)->count();
        $active = (clone $query)->where('employment_status', 'active')->count();
        $onProbation = (clone $query)->where('employment_status', 'probation')->count();
        $onNotice = (clone $query)->where('employment_status', 'notice')->count();

        return [
            'total' => $total,
            'active' => $active,
            'on_probation' => $onProbation,
            'on_notice' => $onNotice,
            'label' => 'Employee Summary',
        ];
    }

    public function getAttendanceToday(array $config = []): array
    {
        $today = Carbon::today()->toDateString();

        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $totalActive = $query->count();

        $attendanceQuery = Attendance::whereHas('employee', function ($q) {
            $q->where('organization_id', $this->organizationId);
            if ($this->branchId) {
                $q->where('branch_id', $this->branchId);
            }
        })->whereDate('attendance_date', $today);

        $present = (clone $attendanceQuery)->where('status', 'present')->count();
        $late = (clone $attendanceQuery)->where('is_late', true)->count();
        $onLeave = (clone $attendanceQuery)->whereIn('status', ['on_leave', 'half_day_leave'])->count();

        return [
            'total_employees' => $totalActive,
            'present' => $present,
            'late' => $late,
            'on_leave' => $onLeave,
            'attendance_rate' => $totalActive > 0 ? round(($present / $totalActive) * 100, 1) : 0,
            'label' => "Today's Attendance",
        ];
    }

    public function getPendingLeaveApprovals(array $config = []): array
    {
        $count = LeaveRequest::whereHas('employee', function ($q) {
            $q->where('organization_id', $this->organizationId);
            if ($this->branchId) {
                $q->where('branch_id', $this->branchId);
            }
        })->where('status', 'pending')->count();

        return [
            'value' => $count,
            'label' => 'Pending Leave Approvals',
            'severity' => $count > 5 ? 'warning' : 'normal',
        ];
    }

    public function getUpcomingBirthdays(array $config = []): array
    {
        $days = $config['days'] ?? 7;
        $today = Carbon::today();
        $endDate = Carbon::today()->addDays($days);

        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active')
            ->whereNotNull('date_of_birth');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $birthdays = $query->get()
            ->filter(function ($emp) use ($today, $endDate) {
                $birthday = $emp->date_of_birth->setYear($today->year);
                if ($birthday->isPast()) {
                    $birthday = $birthday->addYear();
                }
                return $birthday->between($today, $endDate);
            })
            ->map(function ($emp) use ($today) {
                $birthday = $emp->date_of_birth->setYear($today->year);
                if ($birthday->isPast()) {
                    $birthday = $birthday->addYear();
                }
                return [
                    'name' => $emp->first_name . ' ' . $emp->last_name,
                    'date' => $birthday->format('M d'),
                    'is_today' => $birthday->isToday(),
                ];
            })
            ->sortBy(fn($b) => $b['is_today'] ? 0 : 1)
            ->take(5)
            ->values();

        return [
            'items' => $birthdays->toArray(),
            'label' => 'Upcoming Birthdays',
        ];
    }

    public function getHeadcountByDepartment(array $config = []): array
    {
        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $data = $query->select('department_id', DB::raw('count(*) as count'))
            ->with('department:id,name')
            ->groupBy('department_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return [
            'labels' => $data->map(fn($d) => $d->department->name ?? 'Unassigned')->toArray(),
            'data' => $data->pluck('count')->toArray(),
        ];
    }

    // ==================== CRM Widgets ====================

    public function getLeadsSummary(array $config = []): array
    {
        $query = Lead::where('organization_id', $this->organizationId);

        $total = (clone $query)->count();
        $newThisMonth = (clone $query)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();
        $qualified = (clone $query)->where('status', 'qualified')->count();
        $converted = (clone $query)->where('status', 'converted')->count();

        $conversionRate = $total > 0 ? round(($converted / $total) * 100, 1) : 0;

        return [
            'total' => $total,
            'new_this_month' => $newThisMonth,
            'qualified' => $qualified,
            'converted' => $converted,
            'conversion_rate' => $conversionRate,
            'label' => 'Leads Summary',
        ];
    }

    public function getOpportunitiesPipeline(array $config = []): array
    {
        $opportunities = Opportunity::where('organization_id', $this->organizationId)
            ->whereIn('status', ['open', 'negotiation', 'proposal'])
            ->select('stage', DB::raw('count(*) as count'), DB::raw('sum(amount) as total_value'))
            ->with('pipelineStage:id,name,sequence')
            ->groupBy('stage')
            ->get();

        $totalValue = $opportunities->sum('total_value');
        $totalCount = $opportunities->sum('count');

        return [
            'total_value' => (float) $totalValue,
            'total_count' => $totalCount,
            'stages' => $opportunities->map(fn($o) => [
                'stage' => $o->pipelineStage->name ?? $o->stage,
                'count' => $o->count,
                'value' => (float) $o->total_value,
            ])->sortBy(fn($s) => $s['stage'])->values()->toArray(),
            'label' => 'Opportunities Pipeline',
        ];
    }

    public function getOpportunitiesClosingSoon(array $config = []): array
    {
        $days = $config['days'] ?? 30;

        $opportunities = Opportunity::where('organization_id', $this->organizationId)
            ->whereIn('status', ['open', 'negotiation', 'proposal'])
            ->where('expected_close_date', '<=', Carbon::now()->addDays($days))
            ->where('expected_close_date', '>=', Carbon::today())
            ->orderBy('expected_close_date')
            ->limit(10)
            ->get(['id', 'name', 'amount', 'expected_close_date', 'probability']);

        return [
            'items' => $opportunities->map(fn($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'amount' => (float) $o->amount,
                'close_date' => $o->expected_close_date->format('Y-m-d'),
                'days_left' => $o->expected_close_date->diffInDays(Carbon::today()),
                'probability' => $o->probability,
            ])->toArray(),
            'label' => 'Opportunities Closing Soon',
        ];
    }

    public function getLeadsBySource(array $config = []): array
    {
        $data = Lead::where('organization_id', $this->organizationId)
            ->whereNotNull('lead_source_id')
            ->select('lead_source_id', DB::raw('count(*) as count'))
            ->with('leadSource:id,name')
            ->groupBy('lead_source_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return [
            'labels' => $data->map(fn($l) => $l->leadSource->name ?? 'Unknown')->toArray(),
            'data' => $data->pluck('count')->toArray(),
        ];
    }

    // ==================== Manufacturing Widgets ====================

    public function getWorkOrdersSummary(array $config = []): array
    {
        $query = WorkOrder::where('organization_id', $this->organizationId);

        $total = (clone $query)->count();
        $pending = (clone $query)->where('status', 'pending')->count();
        $inProgress = (clone $query)->where('status', 'in_progress')->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        $overdue = (clone $query)
            ->where('status', '!=', 'completed')
            ->where('due_date', '<', Carbon::today())
            ->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'overdue' => $overdue,
            'label' => 'Work Orders Summary',
        ];
    }

    public function getActiveWorkOrders(array $config = []): array
    {
        $limit = $config['limit'] ?? 10;

        $workOrders = WorkOrder::where('organization_id', $this->organizationId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->with(['product:id,name,sku'])
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        return [
            'items' => $workOrders->map(fn($wo) => [
                'id' => $wo->id,
                'work_order_number' => $wo->work_order_number,
                'product' => $wo->product->name ?? 'Unknown',
                'quantity' => (float) $wo->quantity,
                'completed_quantity' => (float) $wo->completed_quantity,
                'progress' => $wo->quantity > 0
                    ? round(($wo->completed_quantity / $wo->quantity) * 100, 1)
                    : 0,
                'status' => $wo->status,
                'due_date' => $wo->due_date?->format('Y-m-d'),
                'is_overdue' => $wo->due_date && $wo->due_date->isPast() && $wo->status !== 'completed',
            ])->toArray(),
            'label' => 'Active Work Orders',
        ];
    }

    public function getProductionEfficiency(array $config = []): array
    {
        $thisMonth = Carbon::now()->startOfMonth();

        $completed = WorkOrder::where('organization_id', $this->organizationId)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $thisMonth)
            ->get();

        $onTime = $completed->filter(fn($wo) =>
            $wo->completed_at && $wo->due_date && $wo->completed_at->lte($wo->due_date)
        )->count();

        $totalCompleted = $completed->count();
        $onTimeRate = $totalCompleted > 0 ? round(($onTime / $totalCompleted) * 100, 1) : 0;

        return [
            'completed_this_month' => $totalCompleted,
            'on_time_count' => $onTime,
            'on_time_rate' => $onTimeRate,
            'label' => 'Production Efficiency (MTD)',
        ];
    }

    // ==================== Combined Dashboard ====================

    public function getQuickStats(array $config = []): array
    {
        return [
            'sales' => $this->getTodaySales(),
            'pending_invoices' => $this->getPendingInvoices(),
            'low_stock' => $this->getLowStockCount(),
            'employees' => $this->getEmployeeSummary(),
            'leads' => $this->getLeadsSummary(),
            'work_orders' => $this->getWorkOrdersSummary(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\CRM\Lead;
use App\Models\CRM\Opportunity;
use App\Models\HR\Attendance;
use App\Models\HR\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockLevel;
use App\Models\Manufacturing\WorkOrder;
use App\Models\Purchase\Bill;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Sales\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Get comprehensive dashboard statistics.
     */
    public function getDashboardStats(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth();
        $endDate = $endDate ?? now()->endOfMonth();

        return [
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'sales' => $this->getSalesStats($startDate, $endDate),
            'purchase' => $this->getPurchaseStats($startDate, $endDate),
            'inventory' => $this->getInventoryStats(),
            'hr' => $this->getHrStats($startDate, $endDate),
            'crm' => $this->getCrmStats($startDate, $endDate),
            'manufacturing' => $this->getManufacturingStats($startDate, $endDate),
            'recent_activity' => $this->getRecentActivity(),
            'alerts' => $this->getAlerts(),
        ];
    }

    /**
     * Get sales statistics.
     */
    public function getSalesStats(Carbon $startDate, Carbon $endDate): array
    {
        $invoices = Invoice::whereBetween('invoice_date', [$startDate, $endDate]);

        $totalRevenue = (clone $invoices)->whereIn('status', ['sent', 'partial', 'paid'])->sum('total');
        $paidAmount = (clone $invoices)->where('status', 'paid')->sum('total');
        $outstandingAmount = (clone $invoices)->whereIn('status', ['sent', 'partial', 'overdue'])->sum('amount_due');
        $overdueAmount = Invoice::overdue()->sum('amount_due');

        $invoiceCount = (clone $invoices)->count();
        $paidCount = (clone $invoices)->where('status', 'paid')->count();
        $overdueCount = Invoice::overdue()->count();

        // Monthly trend
        $monthlyTrend = Invoice::whereBetween('invoice_date', [$startDate->copy()->subMonths(5)->startOfMonth(), $endDate])
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->selectRaw('DATE_FORMAT(invoice_date, "%Y-%m") as month, SUM(total) as total, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn($item) => [
                'month' => $item->month,
                'total' => (float) $item->total,
                'count' => $item->count,
            ]);

        // Top customers
        $topCustomers = Invoice::whereBetween('invoice_date', [$startDate, $endDate])
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->with('customer:id,company_name,contact_name')
            ->selectRaw('customer_id, SUM(total) as total_amount, COUNT(*) as invoice_count')
            ->groupBy('customer_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'customer_id' => $item->customer_id,
                'customer_name' => $item->customer?->company_name ?? $item->customer?->contact_name ?? 'Unknown',
                'total_amount' => (float) $item->total_amount,
                'invoice_count' => $item->invoice_count,
            ]);

        return [
            'total_revenue' => (float) $totalRevenue,
            'paid_amount' => (float) $paidAmount,
            'outstanding_amount' => (float) $outstandingAmount,
            'overdue_amount' => (float) $overdueAmount,
            'invoice_count' => $invoiceCount,
            'paid_count' => $paidCount,
            'overdue_count' => $overdueCount,
            'collection_rate' => $totalRevenue > 0 ? round(($paidAmount / $totalRevenue) * 100, 2) : 0,
            'monthly_trend' => $monthlyTrend,
            'top_customers' => $topCustomers,
        ];
    }

    /**
     * Get purchase statistics.
     */
    public function getPurchaseStats(Carbon $startDate, Carbon $endDate): array
    {
        $bills = Bill::whereBetween('bill_date', [$startDate, $endDate]);

        $totalExpenses = (clone $bills)->whereIn('status', ['approved', 'partial', 'paid'])->sum('total');
        $paidAmount = (clone $bills)->where('status', 'paid')->sum('total');
        $payableAmount = (clone $bills)->whereIn('status', ['approved', 'partial', 'overdue'])->sum('amount_due');
        $overdueAmount = Bill::overdue()->sum('amount_due');

        $billCount = (clone $bills)->count();
        $overdueCount = Bill::overdue()->count();

        // Purchase orders
        $poCount = PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->count();
        $pendingPOCount = PurchaseOrder::whereIn('status', ['draft', 'sent', 'confirmed'])->count();
        $pendingPOValue = PurchaseOrder::whereIn('status', ['draft', 'sent', 'confirmed'])->sum('total');

        // Top suppliers
        $topSuppliers = Bill::whereBetween('bill_date', [$startDate, $endDate])
            ->whereIn('status', ['approved', 'partial', 'paid'])
            ->with('supplier:id,company_name,contact_name')
            ->selectRaw('supplier_id, SUM(total) as total_amount, COUNT(*) as bill_count')
            ->groupBy('supplier_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'supplier_id' => $item->supplier_id,
                'supplier_name' => $item->supplier?->company_name ?? $item->supplier?->contact_name ?? 'Unknown',
                'total_amount' => (float) $item->total_amount,
                'bill_count' => $item->bill_count,
            ]);

        return [
            'total_expenses' => (float) $totalExpenses,
            'paid_amount' => (float) $paidAmount,
            'payable_amount' => (float) $payableAmount,
            'overdue_amount' => (float) $overdueAmount,
            'bill_count' => $billCount,
            'overdue_count' => $overdueCount,
            'po_count' => $poCount,
            'pending_po_count' => $pendingPOCount,
            'pending_po_value' => (float) $pendingPOValue,
            'top_suppliers' => $topSuppliers,
        ];
    }

    /**
     * Get inventory statistics.
     */
    public function getInventoryStats(): array
    {
        $totalProducts = Product::active()->count();
        $lowStockCount = StockLevel::whereColumn('quantity', '<=', 'reorder_level')->count();
        $outOfStockCount = StockLevel::where('quantity', '<=', 0)->count();

        // Total inventory value
        $inventoryValue = StockLevel::selectRaw('SUM(quantity * average_cost) as total_value')
            ->first()
            ->total_value ?? 0;

        // Low stock products
        $lowStockProducts = StockLevel::with(['product:id,sku,name', 'warehouse:id,name'])
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->where('quantity', '>', 0)
            ->orderBy('quantity')
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'product_id' => $item->product_id,
                'product_sku' => $item->product?->sku,
                'product_name' => $item->product?->name,
                'warehouse' => $item->warehouse?->name,
                'quantity' => (float) $item->quantity,
                'reorder_level' => (float) $item->reorder_level,
            ]);

        // Out of stock products
        $outOfStockProducts = StockLevel::with(['product:id,sku,name', 'warehouse:id,name'])
            ->where('quantity', '<=', 0)
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'product_id' => $item->product_id,
                'product_sku' => $item->product?->sku,
                'product_name' => $item->product?->name,
                'warehouse' => $item->warehouse?->name,
            ]);

        // Top products by value
        $topProductsByValue = StockLevel::with('product:id,sku,name')
            ->selectRaw('product_id, SUM(quantity) as total_quantity, SUM(quantity * average_cost) as total_value')
            ->groupBy('product_id')
            ->orderByDesc('total_value')
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'product_id' => $item->product_id,
                'product_sku' => $item->product?->sku,
                'product_name' => $item->product?->name,
                'quantity' => (float) $item->total_quantity,
                'value' => (float) $item->total_value,
            ]);

        return [
            'total_products' => $totalProducts,
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'total_inventory_value' => (float) $inventoryValue,
            'low_stock_products' => $lowStockProducts,
            'out_of_stock_products' => $outOfStockProducts,
            'top_products_by_value' => $topProductsByValue,
        ];
    }

    /**
     * Get HR statistics.
     */
    public function getHrStats(Carbon $startDate, Carbon $endDate): array
    {
        $totalEmployees = Employee::active()->count();
        $newHires = Employee::whereBetween('joining_date', [$startDate, $endDate])->count();
        $terminations = Employee::whereBetween('termination_date', [$startDate, $endDate])->count();

        // Attendance stats for current month
        $attendanceStats = Attendance::whereBetween('attendance_date', [$startDate, $endDate])
            ->selectRaw("
                COUNT(*) as total_records,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END) as total_late,
                SUM(total_overtime_minutes) as total_overtime_minutes
            ")
            ->first();

        // Leave statistics
        $pendingLeaves = LeaveRequest::pending()->count();
        $approvedLeaves = LeaveRequest::approved()
            ->whereBetween('start_date', [$startDate, $endDate])
            ->count();

        // Employees on leave today
        $onLeaveToday = LeaveRequest::approved()
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->count();

        // Department breakdown
        $departmentStats = Employee::active()
            ->with('department:id,name')
            ->selectRaw('department_id, COUNT(*) as count')
            ->groupBy('department_id')
            ->get()
            ->map(fn($item) => [
                'department_id' => $item->department_id,
                'department_name' => $item->department?->name ?? 'Unassigned',
                'count' => $item->count,
            ]);

        return [
            'total_employees' => $totalEmployees,
            'new_hires' => $newHires,
            'terminations' => $terminations,
            'attrition_rate' => $totalEmployees > 0 ? round(($terminations / $totalEmployees) * 100, 2) : 0,
            'attendance' => [
                'present' => $attendanceStats->present_count ?? 0,
                'absent' => $attendanceStats->absent_count ?? 0,
                'late' => $attendanceStats->late_count ?? 0,
                'overtime_hours' => round(($attendanceStats->total_overtime_minutes ?? 0) / 60, 1),
            ],
            'leave' => [
                'pending_requests' => $pendingLeaves,
                'approved_this_period' => $approvedLeaves,
                'on_leave_today' => $onLeaveToday,
            ],
            'department_breakdown' => $departmentStats,
        ];
    }

    /**
     * Get CRM statistics.
     */
    public function getCrmStats(Carbon $startDate, Carbon $endDate): array
    {
        // Leads
        $newLeads = Lead::whereBetween('created_at', [$startDate, $endDate])->count();
        $convertedLeads = Lead::where('status', Lead::STATUS_CONVERTED)
            ->whereBetween('converted_at', [$startDate, $endDate])
            ->count();
        $qualifiedLeads = Lead::where('status', Lead::STATUS_QUALIFIED)->count();

        // Opportunities
        $openOpportunities = Opportunity::open()->count();
        $openValue = Opportunity::open()->sum('amount');
        $wonOpportunities = Opportunity::where('status', Opportunity::STATUS_WON)
            ->whereBetween('actual_close_date', [$startDate, $endDate])
            ->count();
        $wonValue = Opportunity::where('status', Opportunity::STATUS_WON)
            ->whereBetween('actual_close_date', [$startDate, $endDate])
            ->sum('amount');
        $lostOpportunities = Opportunity::where('status', Opportunity::STATUS_LOST)
            ->whereBetween('actual_close_date', [$startDate, $endDate])
            ->count();

        // Win rate
        $closedCount = $wonOpportunities + $lostOpportunities;
        $winRate = $closedCount > 0 ? round(($wonOpportunities / $closedCount) * 100, 2) : 0;

        // Pipeline by stage
        $pipelineByStage = Opportunity::open()
            ->with('pipelineStage:id,name,color')
            ->selectRaw('pipeline_stage_id, COUNT(*) as count, SUM(amount) as total_value')
            ->groupBy('pipeline_stage_id')
            ->get()
            ->map(fn($item) => [
                'stage_id' => $item->pipeline_stage_id,
                'stage_name' => $item->pipelineStage?->name ?? 'Unknown',
                'color' => $item->pipelineStage?->color,
                'count' => $item->count,
                'value' => (float) $item->total_value,
            ]);

        // Lead sources
        $leadSources = Lead::whereBetween('created_at', [$startDate, $endDate])
            ->with('leadSource:id,name')
            ->selectRaw('lead_source_id, COUNT(*) as count')
            ->groupBy('lead_source_id')
            ->get()
            ->map(fn($item) => [
                'source_id' => $item->lead_source_id,
                'source_name' => $item->leadSource?->name ?? 'Unknown',
                'count' => $item->count,
            ]);

        return [
            'leads' => [
                'new' => $newLeads,
                'converted' => $convertedLeads,
                'qualified' => $qualifiedLeads,
                'conversion_rate' => $newLeads > 0 ? round(($convertedLeads / $newLeads) * 100, 2) : 0,
            ],
            'opportunities' => [
                'open_count' => $openOpportunities,
                'open_value' => (float) $openValue,
                'won_count' => $wonOpportunities,
                'won_value' => (float) $wonValue,
                'lost_count' => $lostOpportunities,
                'win_rate' => $winRate,
            ],
            'pipeline_by_stage' => $pipelineByStage,
            'lead_sources' => $leadSources,
        ];
    }

    /**
     * Get manufacturing statistics.
     */
    public function getManufacturingStats(Carbon $startDate, Carbon $endDate): array
    {
        $workOrders = WorkOrder::whereBetween('created_at', [$startDate, $endDate]);

        $totalWorkOrders = (clone $workOrders)->count();
        $inProgress = WorkOrder::inProgress()->count();
        $completed = (clone $workOrders)->completed()->count();
        $overdue = WorkOrder::overdue()->count();

        // Production stats
        $productionStats = (clone $workOrders)->completed()
            ->selectRaw('
                SUM(planned_quantity) as total_planned,
                SUM(produced_quantity) as total_produced,
                SUM(rejected_quantity) as total_rejected,
                SUM(actual_material_cost) as total_material_cost,
                SUM(actual_labor_cost) as total_labor_cost,
                SUM(actual_overhead_cost) as total_overhead_cost
            ')
            ->first();

        $totalPlanned = (float) ($productionStats->total_planned ?? 0);
        $totalProduced = (float) ($productionStats->total_produced ?? 0);
        $totalRejected = (float) ($productionStats->total_rejected ?? 0);

        $yieldRate = $totalProduced > 0
            ? round((($totalProduced - $totalRejected) / $totalProduced) * 100, 2)
            : 0;

        // Efficiency metrics
        $completionRate = $totalPlanned > 0
            ? round(($totalProduced / $totalPlanned) * 100, 2)
            : 0;

        // Cost analysis
        $totalMaterialCost = (float) ($productionStats->total_material_cost ?? 0);
        $totalLaborCost = (float) ($productionStats->total_labor_cost ?? 0);
        $totalOverheadCost = (float) ($productionStats->total_overhead_cost ?? 0);
        $totalProductionCost = $totalMaterialCost + $totalLaborCost + $totalOverheadCost;

        // Work orders by status
        $byStatus = WorkOrder::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'work_orders' => [
                'total' => $totalWorkOrders,
                'in_progress' => $inProgress,
                'completed' => $completed,
                'overdue' => $overdue,
                'by_status' => $byStatus,
            ],
            'production' => [
                'total_planned' => $totalPlanned,
                'total_produced' => $totalProduced,
                'total_rejected' => $totalRejected,
                'completion_rate' => $completionRate,
                'yield_rate' => $yieldRate,
            ],
            'costs' => [
                'material_cost' => $totalMaterialCost,
                'labor_cost' => $totalLaborCost,
                'overhead_cost' => $totalOverheadCost,
                'total_cost' => $totalProductionCost,
            ],
        ];
    }

    /**
     * Get recent activity across all modules.
     */
    public function getRecentActivity(int $limit = 20): array
    {
        $activities = collect();

        // Recent invoices
        $recentInvoices = Invoice::with('customer:id,company_name,contact_name')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'type' => 'invoice',
                'id' => $item->id,
                'title' => "Invoice {$item->invoice_number}",
                'description' => "Customer: " . ($item->customer?->company_name ?? $item->customer_name),
                'amount' => (float) $item->total,
                'status' => $item->status,
                'created_at' => $item->created_at,
            ]);

        // Recent work orders
        $recentWorkOrders = WorkOrder::with('product:id,name')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'type' => 'work_order',
                'id' => $item->id,
                'title' => "Work Order {$item->work_order_number}",
                'description' => "Product: " . ($item->product?->name ?? 'Unknown'),
                'quantity' => (float) $item->planned_quantity,
                'status' => $item->status,
                'created_at' => $item->created_at,
            ]);

        // Recent leave requests
        $recentLeaves = LeaveRequest::with(['employee:id,first_name,last_name', 'leaveType:id,name'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'type' => 'leave_request',
                'id' => $item->id,
                'title' => "Leave Request: {$item->leaveType?->name}",
                'description' => "Employee: {$item->employee?->first_name} {$item->employee?->last_name}",
                'days' => $item->total_days,
                'status' => $item->status,
                'created_at' => $item->created_at,
            ]);

        return $activities
            ->merge($recentInvoices)
            ->merge($recentWorkOrders)
            ->merge($recentLeaves)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->toArray();
    }

    /**
     * Get system alerts.
     */
    public function getAlerts(): array
    {
        $alerts = [];

        // Low stock alerts
        $lowStockCount = StockLevel::whereColumn('quantity', '<=', 'reorder_level')
            ->where('quantity', '>', 0)
            ->count();
        if ($lowStockCount > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'inventory',
                'title' => 'Low Stock Items',
                'message' => "{$lowStockCount} products are below reorder level",
                'action_url' => '/inventory/low-stock',
            ];
        }

        // Out of stock
        $outOfStockCount = StockLevel::where('quantity', '<=', 0)->count();
        if ($outOfStockCount > 0) {
            $alerts[] = [
                'type' => 'danger',
                'category' => 'inventory',
                'title' => 'Out of Stock',
                'message' => "{$outOfStockCount} products are out of stock",
                'action_url' => '/inventory/out-of-stock',
            ];
        }

        // Overdue invoices
        $overdueInvoices = Invoice::overdue()->count();
        if ($overdueInvoices > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'sales',
                'title' => 'Overdue Invoices',
                'message' => "{$overdueInvoices} invoices are overdue",
                'action_url' => '/sales/invoices?filter=overdue',
            ];
        }

        // Overdue bills
        $overdueBills = Bill::overdue()->count();
        if ($overdueBills > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'purchase',
                'title' => 'Overdue Bills',
                'message' => "{$overdueBills} bills are overdue",
                'action_url' => '/purchase/bills?filter=overdue',
            ];
        }

        // Pending leave requests
        $pendingLeaves = LeaveRequest::pending()->count();
        if ($pendingLeaves > 0) {
            $alerts[] = [
                'type' => 'info',
                'category' => 'hr',
                'title' => 'Pending Leave Requests',
                'message' => "{$pendingLeaves} leave requests awaiting approval",
                'action_url' => '/hr/leave-requests?filter=pending',
            ];
        }

        // Overdue work orders
        $overdueWorkOrders = WorkOrder::overdue()->count();
        if ($overdueWorkOrders > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'manufacturing',
                'title' => 'Overdue Work Orders',
                'message' => "{$overdueWorkOrders} work orders are overdue",
                'action_url' => '/manufacturing/work-orders?filter=overdue',
            ];
        }

        return $alerts;
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class DashboardWidget extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'type',
        'default_config',
        'available_sizes',
        'data_source',
        'permission',
        'module',
        'is_premium',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'default_config' => 'array',
        'available_sizes' => 'array',
        'is_premium' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Widget categories
    public const CATEGORY_KPI = 'kpi';
    public const CATEGORY_CHART = 'chart';
    public const CATEGORY_TABLE = 'table';
    public const CATEGORY_LIST = 'list';
    public const CATEGORY_CALENDAR = 'calendar';
    public const CATEGORY_MAP = 'map';
    public const CATEGORY_CUSTOM = 'custom';

    // Widget types
    public const TYPE_NUMBER = 'number';
    public const TYPE_CURRENCY = 'currency';
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_LINE_CHART = 'line_chart';
    public const TYPE_BAR_CHART = 'bar_chart';
    public const TYPE_PIE_CHART = 'pie_chart';
    public const TYPE_DOUGHNUT_CHART = 'doughnut_chart';
    public const TYPE_AREA_CHART = 'area_chart';
    public const TYPE_SPARKLINE = 'sparkline';
    public const TYPE_GAUGE = 'gauge';
    public const TYPE_PROGRESS = 'progress';
    public const TYPE_TABLE = 'table';
    public const TYPE_LIST = 'list';
    public const TYPE_CALENDAR = 'calendar';
    public const TYPE_FUNNEL = 'funnel';
    public const TYPE_HEATMAP = 'heatmap';

    // Default widgets
    public const DEFAULT_WIDGETS = [
        // Sales KPIs
        [
            'code' => 'total_sales_today',
            'name' => 'Today\'s Sales',
            'category' => self::CATEGORY_KPI,
            'type' => self::TYPE_CURRENCY,
            'module' => 'sales',
            'data_source' => 'DashboardService@getTodaySales',
            'permission' => 'sales.invoices.view',
            'available_sizes' => ['1x1', '2x1'],
            'default_config' => ['comparison' => 'yesterday', 'show_trend' => true],
        ],
        [
            'code' => 'total_sales_month',
            'name' => 'Monthly Sales',
            'category' => self::CATEGORY_KPI,
            'type' => self::TYPE_CURRENCY,
            'module' => 'sales',
            'data_source' => 'DashboardService@getMonthlySales',
            'permission' => 'sales.invoices.view',
            'available_sizes' => ['1x1', '2x1'],
            'default_config' => ['comparison' => 'last_month', 'show_trend' => true],
        ],
        [
            'code' => 'invoices_pending',
            'name' => 'Pending Invoices',
            'category' => self::CATEGORY_KPI,
            'type' => self::TYPE_NUMBER,
            'module' => 'sales',
            'data_source' => 'DashboardService@getPendingInvoices',
            'permission' => 'sales.invoices.view',
            'available_sizes' => ['1x1'],
        ],
        [
            'code' => 'outstanding_receivables',
            'name' => 'Outstanding Receivables',
            'category' => self::CATEGORY_KPI,
            'type' => self::TYPE_CURRENCY,
            'module' => 'sales',
            'data_source' => 'DashboardService@getOutstandingReceivables',
            'permission' => 'sales.invoices.view',
            'available_sizes' => ['1x1', '2x1'],
            'default_config' => ['show_overdue' => true],
        ],
        [
            'code' => 'sales_trend',
            'name' => 'Sales Trend',
            'category' => self::CATEGORY_CHART,
            'type' => self::TYPE_LINE_CHART,
            'module' => 'sales',
            'data_source' => 'DashboardService@getSalesTrend',
            'permission' => 'sales.invoices.view',
            'available_sizes' => ['2x2', '3x2', '4x2'],
            'default_config' => ['period' => '30days', 'group_by' => 'day'],
        ],
        [
            'code' => 'top_products',
            'name' => 'Top Selling Products',
            'category' => self::CATEGORY_TABLE,
            'type' => self::TYPE_TABLE,
            'module' => 'sales',
            'data_source' => 'DashboardService@getTopProducts',
            'permission' => 'inventory.products.view',
            'available_sizes' => ['2x2', '3x2'],
            'default_config' => ['limit' => 10, 'period' => 'month'],
        ],
        [
            'code' => 'top_customers',
            'name' => 'Top Customers',
            'category' => self::CATEGORY_TABLE,
            'type' => self::TYPE_TABLE,
            'module' => 'sales',
            'data_source' => 'DashboardService@getTopCustomers',
            'permission' => 'sales.customers.view',
            'available_sizes' => ['2x2', '3x2'],
            'default_config' => ['limit' => 10, 'period' => 'month'],
        ],
        [
            'code' => 'sales_by_category',
            'name' => 'Sales by Category',
            'category' => self::CATEGORY_CHART,
            'type' => self::TYPE_PIE_CHART,
            'module' => 'sales',
            'data_source' => 'DashboardService@getSalesByCategory',
            'permission' => 'sales.invoices.view',
            'available_sizes' => ['2x2'],
            'default_config' => ['period' => 'month'],
        ],

        // Inventory KPIs
        [
            'code' => 'low_stock_alerts',
            'name' => 'Low Stock Alerts',
            'category' => self::CATEGORY_KPI,
            'type' => self::TYPE_NUMBER,
            'module' => 'inventory',
            'data_source' => 'DashboardService@getLowStockCount',
            'permission' => 'inventory.stock.view',
            'available_sizes' => ['1x1'],
            'default_config' => ['threshold' => 'reorder_level'],
        ],
        [
            'code' => 'inventory_value',
            'name' => 'Total Inventory Value',
            'category' => self::CATEGORY_KPI,
            'type' => self::TYPE_CURRENCY,
            'module' => 'inventory',
            'data_source' => 'DashboardService@getInventoryValue',
            'permission' => 'inventory.stock.view',
            'available_sizes' => ['1x1', '2x1'],
        ],
        [
            'code' => 'expiring_products',
            'name' => 'Expiring Soon',
            'category' => self::CATEGORY_LIST,
            'type' => self::TYPE_LIST,
            'module' => 'inventory',
            'data_source' => 'DashboardService@getExpiringProducts',
            'permission' => 'inventory.stock.view',
            'available_sizes' => ['2x2'],
            'default_config' => ['days' => 30],
        ],
        [
            'code' => 'stock_movement',
            'name' => 'Stock Movement',
            'category' => self::CATEGORY_CHART,
            'type' => self::TYPE_BAR_CHART,
            'module' => 'inventory',
            'data_source' => 'DashboardService@getStockMovement',
            'permission' => 'inventory.stock.view',
            'available_sizes' => ['2x2', '3x2'],
        ],

        // Finance KPIs
        [
            'code' => 'cash_balance',
            'name' => 'Cash Balance',
            'category' => self::CATEGORY_KPI,
            'type' => self::TYPE_CURRENCY,
            'module' => 'accounting',
            'data_source' => 'DashboardService@getCashBalance',
            'permission' => 'accounting.view',
            'available_sizes' => ['1x1', '2x1'],
        ],
        [
            'code' => 'profit_loss_summary',
            'name' => 'P&L Summary',
            'category' => self::CATEGORY_KPI,
            'type' => self::TYPE_CURRENCY,
            'module' => 'accounting',
            'data_source' => 'DashboardService@getProfitLossSummary',
            'permission' => 'accounting.reports.view',
            'available_sizes' => ['2x1', '2x2'],
            'is_premium' => true,
        ],
        [
            'code' => 'revenue_vs_expense',
            'name' => 'Revenue vs Expense',
            'category' => self::CATEGORY_CHART,
            'type' => self::TYPE_AREA_CHART,
            'module' => 'accounting',
            'data_source' => 'DashboardService@getRevenueVsExpense',
            'permission' => 'accounting.reports.view',
            'available_sizes' => ['2x2', '3x2', '4x2'],
            'is_premium' => true,
        ],
        [
            'code' => 'outstanding_payables',
            'name' => 'Outstanding Payables',
            'category' => self::CATEGORY_KPI,
            'type' => self::TYPE_CURRENCY,
            'module' => 'purchase',
            'data_source' => 'DashboardService@getOutstandingPayables',
            'permission' => 'purchase.bills.view',
            'available_sizes' => ['1x1', '2x1'],
        ],

        // Activity widgets
        [
            'code' => 'recent_invoices',
            'name' => 'Recent Invoices',
            'category' => self::CATEGORY_LIST,
            'type' => self::TYPE_LIST,
            'module' => 'sales',
            'data_source' => 'DashboardService@getRecentInvoices',
            'permission' => 'sales.invoices.view',
            'available_sizes' => ['2x2', '2x3'],
            'default_config' => ['limit' => 10],
        ],
        [
            'code' => 'activity_timeline',
            'name' => 'Activity Timeline',
            'category' => self::CATEGORY_LIST,
            'type' => self::TYPE_LIST,
            'module' => 'core',
            'data_source' => 'DashboardService@getActivityTimeline',
            'permission' => null,
            'available_sizes' => ['2x2', '2x3'],
            'default_config' => ['limit' => 20],
        ],

        // Premium widgets
        [
            'code' => 'sales_forecast',
            'name' => 'Sales Forecast',
            'category' => self::CATEGORY_CHART,
            'type' => self::TYPE_LINE_CHART,
            'module' => 'sales',
            'data_source' => 'DashboardService@getSalesForecast',
            'permission' => 'sales.invoices.view',
            'available_sizes' => ['3x2', '4x2'],
            'is_premium' => true,
        ],
        [
            'code' => 'customer_analytics',
            'name' => 'Customer Analytics',
            'category' => self::CATEGORY_CHART,
            'type' => self::TYPE_FUNNEL,
            'module' => 'crm',
            'data_source' => 'DashboardService@getCustomerAnalytics',
            'permission' => 'crm.view',
            'available_sizes' => ['2x2', '3x2'],
            'is_premium' => true,
        ],
        [
            'code' => 'regional_sales_map',
            'name' => 'Regional Sales',
            'category' => self::CATEGORY_MAP,
            'type' => self::TYPE_HEATMAP,
            'module' => 'sales',
            'data_source' => 'DashboardService@getRegionalSales',
            'permission' => 'sales.invoices.view',
            'available_sizes' => ['3x2', '4x3'],
            'is_premium' => true,
        ],
    ];

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopePremium($query)
    {
        return $query->where('is_premium', true);
    }

    public function scopeFree($query)
    {
        return $query->where('is_premium', false);
    }

    // Helpers

    public static function getByCode(string $code): ?self
    {
        return Cache::remember("widget.{$code}", 3600, function () use ($code) {
            return static::where('code', $code)->first();
        });
    }

    public static function getAvailableWidgets(?string $module = null, bool $includePremium = true): \Illuminate\Support\Collection
    {
        $query = static::active()->orderBy('sort_order');

        if ($module) {
            $query->forModule($module);
        }

        if (!$includePremium) {
            $query->free();
        }

        return $query->get();
    }

    public static function getCategories(): array
    {
        return [
            self::CATEGORY_KPI => 'Key Performance Indicators',
            self::CATEGORY_CHART => 'Charts & Graphs',
            self::CATEGORY_TABLE => 'Tables',
            self::CATEGORY_LIST => 'Lists',
            self::CATEGORY_CALENDAR => 'Calendar',
            self::CATEGORY_MAP => 'Maps',
            self::CATEGORY_CUSTOM => 'Custom',
        ];
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_NUMBER => 'Number',
            self::TYPE_CURRENCY => 'Currency',
            self::TYPE_PERCENTAGE => 'Percentage',
            self::TYPE_LINE_CHART => 'Line Chart',
            self::TYPE_BAR_CHART => 'Bar Chart',
            self::TYPE_PIE_CHART => 'Pie Chart',
            self::TYPE_DOUGHNUT_CHART => 'Doughnut Chart',
            self::TYPE_AREA_CHART => 'Area Chart',
            self::TYPE_SPARKLINE => 'Sparkline',
            self::TYPE_GAUGE => 'Gauge',
            self::TYPE_PROGRESS => 'Progress',
            self::TYPE_TABLE => 'Table',
            self::TYPE_LIST => 'List',
            self::TYPE_FUNNEL => 'Funnel',
            self::TYPE_HEATMAP => 'Heatmap',
        ];
    }
}

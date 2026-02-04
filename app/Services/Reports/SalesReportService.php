<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

class SalesReportService
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
     * Generate Sales by Customer Report.
     */
    public function generateSalesByCustomer(
        string $startDate,
        string $endDate,
        ?int $customerId = null,
        int $limit = 50
    ): array {
        $query = DB::table('invoices as i')
            ->leftJoin('contacts as c', 'i.customer_id', '=', 'c.id')
            ->where('i.organization_id', $this->organizationId)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$startDate, $endDate]);

        if ($this->branchId) {
            $query->where('i.branch_id', $this->branchId);
        }

        if ($customerId) {
            $query->where('i.customer_id', $customerId);
        }

        $customers = $query->groupBy('i.customer_id', 'c.company_name', 'i.customer_name', 'i.currency_code')
            ->select([
                'i.customer_id',
                DB::raw('COALESCE(c.company_name, i.customer_name) as customer_name'),
                'i.currency_code',
                DB::raw('COUNT(DISTINCT i.id) as invoice_count'),
                DB::raw('SUM(i.subtotal) as subtotal'),
                DB::raw('SUM(i.discount_amount) as discount'),
                DB::raw('SUM(i.tax_amount) as tax'),
                DB::raw('SUM(i.total) as total'),
                DB::raw('SUM(i.amount_paid) as paid'),
                DB::raw('SUM(i.amount_due) as outstanding'),
                DB::raw('AVG(i.total) as avg_invoice'),
            ])
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $grandTotal = $customers->sum('total');
        $totalPaid = $customers->sum('paid');
        $totalOutstanding = $customers->sum('outstanding');

        // Calculate customer rankings
        $items = $customers->map(function ($customer, $index) use ($grandTotal) {
            return [
                'rank' => $index + 1,
                'customer_id' => $customer->customer_id,
                'customer_name' => $customer->customer_name ?? 'Walk-in Customer',
                'currency_code' => $customer->currency_code,
                'invoice_count' => (int) $customer->invoice_count,
                'subtotal' => (float) $customer->subtotal,
                'discount' => (float) $customer->discount,
                'tax' => (float) $customer->tax,
                'total' => (float) $customer->total,
                'paid' => (float) $customer->paid,
                'outstanding' => (float) $customer->outstanding,
                'average_invoice' => (float) $customer->avg_invoice,
                'percentage_of_total' => $grandTotal > 0 ? round(($customer->total / $grandTotal) * 100, 2) : 0,
            ];
        })->values()->toArray();

        return [
            'report_type' => 'sales_by_customer',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'filters' => [
                'customer_id' => $customerId,
                'branch_id' => $this->branchId,
            ],
            'customers' => $items,
            'summary' => [
                'customer_count' => count($items),
                'total_invoices' => (int) $customers->sum('invoice_count'),
                'total_sales' => (float) $grandTotal,
                'total_paid' => (float) $totalPaid,
                'total_outstanding' => (float) $totalOutstanding,
                'collection_rate' => $grandTotal > 0 ? round(($totalPaid / $grandTotal) * 100, 2) : 0,
                'average_per_customer' => count($items) > 0 ? round($grandTotal / count($items), 2) : 0,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Sales by Product Report.
     */
    public function generateSalesByProduct(
        string $startDate,
        string $endDate,
        ?int $categoryId = null,
        ?int $productId = null,
        int $limit = 50
    ): array {
        $query = DB::table('document_lines as dl')
            ->join('invoices as i', function ($join) {
                $join->on('dl.document_id', '=', 'i.id')
                    ->where('dl.document_type', '=', 'invoice');
            })
            ->leftJoin('products as p', 'dl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('units_of_measure as u', 'dl.unit_id', '=', 'u.id')
            ->where('i.organization_id', $this->organizationId)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$startDate, $endDate]);

        if ($this->branchId) {
            $query->where('i.branch_id', $this->branchId);
        }

        if ($categoryId) {
            $query->where('p.category_id', $categoryId);
        }

        if ($productId) {
            $query->where('dl.product_id', $productId);
        }

        $products = $query->groupBy('dl.product_id', 'p.sku', 'p.name', 'dl.description', 'c.name', 'u.symbol')
            ->select([
                'dl.product_id',
                'p.sku',
                DB::raw('COALESCE(p.name, dl.description) as product_name'),
                'c.name as category',
                'u.symbol as unit',
                DB::raw('COUNT(DISTINCT i.id) as invoice_count'),
                DB::raw('SUM(dl.quantity) as quantity_sold'),
                DB::raw('SUM(dl.subtotal) as subtotal'),
                DB::raw('SUM(dl.discount_amount) as discount'),
                DB::raw('SUM(dl.tax_amount) as tax'),
                DB::raw('SUM(dl.total) as total'),
                DB::raw('AVG(dl.unit_price) as avg_price'),
            ])
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $grandTotal = $products->sum('total');
        $totalQuantity = $products->sum('quantity_sold');

        // Get cost data for profit margin
        $productIds = $products->pluck('product_id')->filter()->toArray();
        $costs = !empty($productIds) ? DB::table('products')
            ->whereIn('id', $productIds)
            ->pluck('purchase_price', 'id')
            ->toArray() : [];

        $items = $products->map(function ($product, $index) use ($grandTotal, $totalQuantity, $costs) {
            $cost = $costs[$product->product_id] ?? 0;
            $totalCost = $cost * $product->quantity_sold;
            $grossProfit = $product->total - $totalCost;
            $margin = $product->total > 0 ? ($grossProfit / $product->total) * 100 : 0;

            return [
                'rank' => $index + 1,
                'product_id' => $product->product_id,
                'sku' => $product->sku,
                'product_name' => $product->product_name ?? 'Custom Item',
                'category' => $product->category ?? 'Uncategorized',
                'unit' => $product->unit,
                'invoice_count' => (int) $product->invoice_count,
                'quantity_sold' => (float) $product->quantity_sold,
                'subtotal' => (float) $product->subtotal,
                'discount' => (float) $product->discount,
                'tax' => (float) $product->tax,
                'total' => (float) $product->total,
                'average_price' => (float) $product->avg_price,
                'unit_cost' => (float) $cost,
                'gross_profit' => (float) $grossProfit,
                'margin_percentage' => round($margin, 2),
                'percentage_of_total' => $grandTotal > 0 ? round(($product->total / $grandTotal) * 100, 2) : 0,
                'percentage_of_quantity' => $totalQuantity > 0 ? round(($product->quantity_sold / $totalQuantity) * 100, 2) : 0,
            ];
        })->values()->toArray();

        // Category breakdown
        $byCategory = collect($items)->groupBy('category')->map(function ($products, $category) use ($grandTotal) {
            $categoryTotal = $products->sum('total');
            return [
                'category' => $category,
                'product_count' => $products->count(),
                'quantity_sold' => $products->sum('quantity_sold'),
                'total' => $categoryTotal,
                'gross_profit' => $products->sum('gross_profit'),
                'percentage_of_total' => $grandTotal > 0 ? round(($categoryTotal / $grandTotal) * 100, 2) : 0,
            ];
        })->sortByDesc('total')->values()->toArray();

        return [
            'report_type' => 'sales_by_product',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'filters' => [
                'category_id' => $categoryId,
                'product_id' => $productId,
                'branch_id' => $this->branchId,
            ],
            'products' => $items,
            'by_category' => $byCategory,
            'summary' => [
                'product_count' => count($items),
                'total_quantity' => (float) $totalQuantity,
                'total_sales' => (float) $grandTotal,
                'total_gross_profit' => (float) collect($items)->sum('gross_profit'),
                'average_margin' => count($items) > 0 ? round(collect($items)->avg('margin_percentage'), 2) : 0,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Sales by Salesperson Report.
     */
    public function generateSalesBySalesperson(string $startDate, string $endDate): array
    {
        $query = DB::table('invoices as i')
            ->leftJoin('users as u', 'i.salesperson_id', '=', 'u.id')
            ->where('i.organization_id', $this->organizationId)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$startDate, $endDate]);

        if ($this->branchId) {
            $query->where('i.branch_id', $this->branchId);
        }

        $salespeople = $query->groupBy('i.salesperson_id', 'u.name')
            ->select([
                'i.salesperson_id',
                DB::raw('COALESCE(u.name, \'Unassigned\') as salesperson_name'),
                DB::raw('COUNT(DISTINCT i.id) as invoice_count'),
                DB::raw('COUNT(DISTINCT i.customer_id) as customer_count'),
                DB::raw('SUM(i.subtotal) as subtotal'),
                DB::raw('SUM(i.discount_amount) as discount'),
                DB::raw('SUM(i.tax_amount) as tax'),
                DB::raw('SUM(i.total) as total'),
                DB::raw('SUM(i.amount_paid) as collected'),
                DB::raw('AVG(i.total) as avg_invoice'),
            ])
            ->orderByDesc('total')
            ->get();

        $grandTotal = $salespeople->sum('total');
        $totalCollected = $salespeople->sum('collected');

        // Get quotation conversion data
        $salespersonIds = $salespeople->pluck('salesperson_id')->filter()->toArray();
        $quotations = !empty($salespersonIds) ? DB::table('quotations')
            ->where('organization_id', $this->organizationId)
            ->whereIn('salesperson_id', $salespersonIds)
            ->whereBetween('quotation_date', [$startDate, $endDate])
            ->groupBy('salesperson_id')
            ->select([
                'salesperson_id',
                DB::raw('COUNT(*) as total_quotations'),
                DB::raw('SUM(CASE WHEN status = \'accepted\' THEN 1 ELSE 0 END) as accepted_quotations'),
            ])
            ->get()
            ->keyBy('salesperson_id')
            ->toArray() : [];

        $items = $salespeople->map(function ($sp, $index) use ($grandTotal, $quotations) {
            $spQuotations = $quotations[$sp->salesperson_id] ?? null;
            $totalQuotes = $spQuotations->total_quotations ?? 0;
            $acceptedQuotes = $spQuotations->accepted_quotations ?? 0;
            $conversionRate = $totalQuotes > 0 ? ($acceptedQuotes / $totalQuotes) * 100 : 0;

            return [
                'rank' => $index + 1,
                'salesperson_id' => $sp->salesperson_id,
                'salesperson_name' => $sp->salesperson_name,
                'invoice_count' => (int) $sp->invoice_count,
                'customer_count' => (int) $sp->customer_count,
                'subtotal' => (float) $sp->subtotal,
                'discount' => (float) $sp->discount,
                'tax' => (float) $sp->tax,
                'total' => (float) $sp->total,
                'collected' => (float) $sp->collected,
                'outstanding' => (float) ($sp->total - $sp->collected),
                'average_invoice' => (float) $sp->avg_invoice,
                'quotations_sent' => (int) $totalQuotes,
                'quotations_accepted' => (int) $acceptedQuotes,
                'conversion_rate' => round($conversionRate, 2),
                'percentage_of_total' => $grandTotal > 0 ? round(($sp->total / $grandTotal) * 100, 2) : 0,
            ];
        })->values()->toArray();

        return [
            'report_type' => 'sales_by_salesperson',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'filters' => [
                'branch_id' => $this->branchId,
            ],
            'salespeople' => $items,
            'summary' => [
                'salesperson_count' => count($items),
                'total_invoices' => (int) $salespeople->sum('invoice_count'),
                'total_sales' => (float) $grandTotal,
                'total_collected' => (float) $totalCollected,
                'collection_rate' => $grandTotal > 0 ? round(($totalCollected / $grandTotal) * 100, 2) : 0,
                'average_per_salesperson' => count($items) > 0 ? round($grandTotal / count($items), 2) : 0,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Sales Trend Report.
     */
    public function generateSalesTrend(
        string $startDate,
        string $endDate,
        string $groupBy = 'day' // day, week, month
    ): array {
        $dateFormat = match ($groupBy) {
            'week' => '%Y-W%V',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $query = DB::table('invoices')
            ->where('organization_id', $this->organizationId)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->whereBetween('invoice_date', [$startDate, $endDate]);

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $trends = $query->groupBy('period')
            ->select([
                DB::raw("DATE_FORMAT(invoice_date, '{$dateFormat}') as period"),
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('SUM(subtotal) as subtotal'),
                DB::raw('SUM(discount_amount) as discount'),
                DB::raw('SUM(tax_amount) as tax'),
                DB::raw('SUM(total) as total'),
                DB::raw('AVG(total) as average'),
            ])
            ->orderBy('period')
            ->get();

        // Calculate growth rates
        $periods = [];
        $previousTotal = null;
        foreach ($trends as $trend) {
            $growthRate = null;
            if ($previousTotal !== null && $previousTotal > 0) {
                $growthRate = round((($trend->total - $previousTotal) / $previousTotal) * 100, 2);
            }

            $periods[] = [
                'period' => $trend->period,
                'invoice_count' => (int) $trend->invoice_count,
                'subtotal' => (float) $trend->subtotal,
                'discount' => (float) $trend->discount,
                'tax' => (float) $trend->tax,
                'total' => (float) $trend->total,
                'average' => (float) $trend->average,
                'growth_rate' => $growthRate,
            ];

            $previousTotal = $trend->total;
        }

        $totals = collect($periods);
        $grandTotal = $totals->sum('total');
        $avgPeriodSales = $totals->avg('total');

        return [
            'report_type' => 'sales_trend',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'group_by' => $groupBy,
            'filters' => [
                'branch_id' => $this->branchId,
            ],
            'periods' => $periods,
            'summary' => [
                'total_periods' => count($periods),
                'total_invoices' => (int) $totals->sum('invoice_count'),
                'total_sales' => (float) $grandTotal,
                'average_per_period' => (float) $avgPeriodSales,
                'best_period' => $totals->sortByDesc('total')->first()['period'] ?? null,
                'worst_period' => $totals->sortBy('total')->first()['period'] ?? null,
                'overall_growth' => count($periods) > 1
                    ? round((($periods[count($periods) - 1]['total'] - $periods[0]['total']) / max($periods[0]['total'], 1)) * 100, 2)
                    : null,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Sales Summary Dashboard.
     */
    public function generateSalesSummary(string $startDate, string $endDate): array
    {
        $baseQuery = function () use ($startDate, $endDate) {
            $query = DB::table('invoices')
                ->where('organization_id', $this->organizationId)
                ->whereIn('status', ['sent', 'partial', 'paid'])
                ->whereBetween('invoice_date', [$startDate, $endDate]);

            if ($this->branchId) {
                $query->where('branch_id', $this->branchId);
            }

            return $query;
        };

        // Overall totals
        $totals = $baseQuery()->select([
            DB::raw('COUNT(*) as invoice_count'),
            DB::raw('SUM(subtotal) as subtotal'),
            DB::raw('SUM(discount_amount) as discount'),
            DB::raw('SUM(tax_amount) as tax'),
            DB::raw('SUM(total) as total'),
            DB::raw('SUM(amount_paid) as paid'),
            DB::raw('SUM(amount_due) as outstanding'),
            DB::raw('AVG(total) as avg_invoice'),
        ])->first();

        // By status
        $byStatus = $baseQuery()->groupBy('status')
            ->select([
                'status',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total) as total'),
            ])
            ->get()
            ->keyBy('status')
            ->toArray();

        // By invoice type
        $byType = $baseQuery()->groupBy('invoice_type')
            ->select([
                'invoice_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total) as total'),
            ])
            ->get()
            ->keyBy('invoice_type')
            ->toArray();

        // Top 5 customers
        $topCustomers = $baseQuery()
            ->leftJoin('contacts as c', 'invoices.customer_id', '=', 'c.id')
            ->groupBy('invoices.customer_id', 'c.company_name', 'invoices.customer_name')
            ->select([
                'invoices.customer_id',
                DB::raw('COALESCE(c.company_name, invoices.customer_name) as customer_name'),
                DB::raw('SUM(invoices.total) as total'),
            ])
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->toArray();

        // Top 5 products
        $topProducts = DB::table('document_lines as dl')
            ->join('invoices as i', function ($join) {
                $join->on('dl.document_id', '=', 'i.id')
                    ->where('dl.document_type', '=', 'invoice');
            })
            ->leftJoin('products as p', 'dl.product_id', '=', 'p.id')
            ->where('i.organization_id', $this->organizationId)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$startDate, $endDate])
            ->when($this->branchId, fn($q) => $q->where('i.branch_id', $this->branchId))
            ->groupBy('dl.product_id', 'p.name', 'dl.description')
            ->select([
                'dl.product_id',
                DB::raw('COALESCE(p.name, dl.description) as product_name'),
                DB::raw('SUM(dl.quantity) as quantity'),
                DB::raw('SUM(dl.total) as total'),
            ])
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->toArray();

        return [
            'report_type' => 'sales_summary',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'totals' => [
                'invoice_count' => (int) ($totals->invoice_count ?? 0),
                'subtotal' => (float) ($totals->subtotal ?? 0),
                'discount' => (float) ($totals->discount ?? 0),
                'tax' => (float) ($totals->tax ?? 0),
                'total' => (float) ($totals->total ?? 0),
                'paid' => (float) ($totals->paid ?? 0),
                'outstanding' => (float) ($totals->outstanding ?? 0),
                'average_invoice' => (float) ($totals->avg_invoice ?? 0),
                'collection_rate' => ($totals->total ?? 0) > 0
                    ? round((($totals->paid ?? 0) / $totals->total) * 100, 2)
                    : 0,
            ],
            'by_status' => $byStatus,
            'by_type' => $byType,
            'top_customers' => $topCustomers,
            'top_products' => $topProducts,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}

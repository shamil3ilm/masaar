<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryReportService
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
     * Generate Stock Valuation Report.
     */
    public function generateStockValuation(
        ?int $warehouseId = null,
        ?int $categoryId = null,
        ?string $valuationMethod = null
    ): array {
        $query = DB::table('stock_levels as sl')
            ->join('products as p', 'sl.product_id', '=', 'p.id')
            ->join('warehouses as w', 'sl.warehouse_id', '=', 'w.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('units_of_measure as u', 'p.unit_id', '=', 'u.id')
            ->where('sl.organization_id', $this->organizationId)
            ->where('p.type', 'goods') // Only physical inventory
            ->where('sl.quantity', '>', 0);

        if ($warehouseId) {
            $query->where('sl.warehouse_id', $warehouseId);
        }

        if ($categoryId) {
            $query->where('p.category_id', $categoryId);
        }

        $stocks = $query->select([
            'p.id as product_id',
            'p.sku',
            'p.name as product_name',
            'p.costing_method',
            'c.name as category_name',
            'w.id as warehouse_id',
            'w.name as warehouse_name',
            'u.symbol as unit',
            'sl.quantity',
            'sl.reserved_quantity',
            'sl.average_cost',
            'sl.last_purchase_price',
        ])
            ->orderBy('c.name')
            ->orderBy('p.name')
            ->get();

        $items = [];
        $totalValue = 0;
        $totalQuantity = 0;
        $categoryTotals = [];

        foreach ($stocks as $stock) {
            $method = $valuationMethod ?? $stock->costing_method ?? 'weighted_average';
            $unitCost = $this->getUnitCost($stock, $method);
            $totalCost = $stock->quantity * $unitCost;
            $availableQty = $stock->quantity - ($stock->reserved_quantity ?? 0);

            $items[] = [
                'product_id' => $stock->product_id,
                'sku' => $stock->sku,
                'product_name' => $stock->product_name,
                'category' => $stock->category_name ?? 'Uncategorized',
                'warehouse_id' => $stock->warehouse_id,
                'warehouse' => $stock->warehouse_name,
                'unit' => $stock->unit,
                'quantity' => (float) $stock->quantity,
                'reserved' => (float) ($stock->reserved_quantity ?? 0),
                'available' => (float) $availableQty,
                'unit_cost' => (float) $unitCost,
                'total_value' => (float) $totalCost,
                'valuation_method' => $method,
            ];

            $totalValue += $totalCost;
            $totalQuantity += $stock->quantity;

            $category = $stock->category_name ?? 'Uncategorized';
            if (!isset($categoryTotals[$category])) {
                $categoryTotals[$category] = ['quantity' => 0, 'value' => 0];
            }
            $categoryTotals[$category]['quantity'] += $stock->quantity;
            $categoryTotals[$category]['value'] += $totalCost;
        }

        // Get warehouse breakdown
        $warehouseTotals = collect($items)->groupBy('warehouse')->map(function ($items) {
            return [
                'quantity' => $items->sum('quantity'),
                'value' => $items->sum('total_value'),
            ];
        })->toArray();

        return [
            'report_type' => 'stock_valuation',
            'as_of_date' => now()->toDateString(),
            'filters' => [
                'warehouse_id' => $warehouseId,
                'category_id' => $categoryId,
                'valuation_method' => $valuationMethod,
            ],
            'items' => $items,
            'summary' => [
                'total_quantity' => (float) $totalQuantity,
                'total_value' => (float) $totalValue,
                'product_count' => count($items),
                'average_item_value' => count($items) > 0 ? $totalValue / count($items) : 0,
            ],
            'by_category' => $categoryTotals,
            'by_warehouse' => $warehouseTotals,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Stock Movement Report.
     */
    public function generateStockMovement(
        string $startDate,
        string $endDate,
        ?int $productId = null,
        ?int $warehouseId = null,
        ?string $movementType = null
    ): array {
        $query = DB::table('stock_movements as sm')
            ->join('products as p', 'sm.product_id', '=', 'p.id')
            ->join('warehouses as w', 'sm.warehouse_id', '=', 'w.id')
            ->leftJoin('units_of_measure as u', 'p.unit_id', '=', 'u.id')
            ->leftJoin('users as usr', 'sm.created_by', '=', 'usr.id')
            ->where('sm.organization_id', $this->organizationId)
            ->whereBetween('sm.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

        if ($productId) {
            $query->where('sm.product_id', $productId);
        }

        if ($warehouseId) {
            $query->where('sm.warehouse_id', $warehouseId);
        }

        if ($movementType) {
            $query->where('sm.movement_type', $movementType);
        }

        $movements = $query->select([
            'sm.id',
            'sm.created_at as movement_date',
            'sm.movement_type',
            'sm.quantity',
            'sm.unit_cost',
            'sm.total_cost',
            'sm.reference_type',
            'sm.reference_id',
            'sm.notes',
            'p.id as product_id',
            'p.sku',
            'p.name as product_name',
            'w.name as warehouse_name',
            'u.symbol as unit',
            'usr.name as created_by',
        ])
            ->orderBy('sm.created_at', 'desc')
            ->get();

        // Calculate summaries
        $inMovements = $movements->whereIn('movement_type', ['in', 'purchase', 'return_in', 'adjustment_in', 'transfer_in']);
        $outMovements = $movements->whereIn('movement_type', ['out', 'sale', 'return_out', 'adjustment_out', 'transfer_out']);

        $summary = [
            'total_in' => (float) $inMovements->sum('quantity'),
            'total_in_value' => (float) $inMovements->sum('total_cost'),
            'total_out' => (float) $outMovements->sum('quantity'),
            'total_out_value' => (float) $outMovements->sum('total_cost'),
            'net_movement' => (float) ($inMovements->sum('quantity') - $outMovements->sum('quantity')),
            'movement_count' => $movements->count(),
        ];

        // Group by movement type
        $byType = $movements->groupBy('movement_type')->map(function ($items, $type) {
            return [
                'type' => $type,
                'label' => $this->getMovementTypeLabel($type),
                'count' => $items->count(),
                'quantity' => (float) $items->sum('quantity'),
                'value' => (float) $items->sum('total_cost'),
            ];
        })->values()->toArray();

        // Group by product
        $byProduct = $movements->groupBy('product_id')->map(function ($items) {
            $first = $items->first();
            $inQty = $items->whereIn('movement_type', ['in', 'purchase', 'return_in', 'adjustment_in', 'transfer_in'])->sum('quantity');
            $outQty = $items->whereIn('movement_type', ['out', 'sale', 'return_out', 'adjustment_out', 'transfer_out'])->sum('quantity');

            return [
                'product_id' => $first->product_id,
                'sku' => $first->sku,
                'product_name' => $first->product_name,
                'movement_count' => $items->count(),
                'total_in' => (float) $inQty,
                'total_out' => (float) $outQty,
                'net' => (float) ($inQty - $outQty),
            ];
        })->sortByDesc('movement_count')->values()->toArray();

        return [
            'report_type' => 'stock_movement',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'filters' => [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'movement_type' => $movementType,
            ],
            'movements' => $movements->map(function ($m) {
                return [
                    'id' => $m->id,
                    'date' => $m->movement_date,
                    'type' => $m->movement_type,
                    'type_label' => $this->getMovementTypeLabel($m->movement_type),
                    'product_id' => $m->product_id,
                    'sku' => $m->sku,
                    'product_name' => $m->product_name,
                    'warehouse' => $m->warehouse_name,
                    'quantity' => (float) $m->quantity,
                    'unit' => $m->unit,
                    'unit_cost' => (float) $m->unit_cost,
                    'total_cost' => (float) $m->total_cost,
                    'reference' => $m->reference_type ? "{$m->reference_type}#{$m->reference_id}" : null,
                    'notes' => $m->notes,
                    'created_by' => $m->created_by,
                ];
            })->values()->toArray(),
            'summary' => $summary,
            'by_type' => $byType,
            'by_product' => array_slice($byProduct, 0, 20), // Top 20 products
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Low Stock Alert Report.
     */
    public function generateLowStockReport(?int $warehouseId = null): array
    {
        $query = DB::table('stock_levels as sl')
            ->join('products as p', 'sl.product_id', '=', 'p.id')
            ->join('warehouses as w', 'sl.warehouse_id', '=', 'w.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('units_of_measure as u', 'p.unit_id', '=', 'u.id')
            ->where('sl.organization_id', $this->organizationId)
            ->where('p.type', 'goods')
            ->where('p.is_active', true)
            ->whereColumn('sl.quantity', '<=', 'sl.reorder_level')
            ->where('sl.reorder_level', '>', 0);

        if ($warehouseId) {
            $query->where('sl.warehouse_id', $warehouseId);
        }

        $items = $query->select([
            'p.id as product_id',
            'p.sku',
            'p.name as product_name',
            'c.name as category_name',
            'w.id as warehouse_id',
            'w.name as warehouse_name',
            'u.symbol as unit',
            'sl.quantity',
            'sl.reserved_quantity',
            'sl.reorder_level',
            'sl.reorder_quantity',
            'sl.average_cost',
        ])
            ->orderByRaw('(sl.reorder_level - sl.quantity) DESC')
            ->get();

        $criticalItems = [];
        $lowItems = [];
        $totalReorderValue = 0;

        foreach ($items as $item) {
            $availableQty = $item->quantity - ($item->reserved_quantity ?? 0);
            $shortfall = $item->reorder_level - $item->quantity;
            $suggestedOrder = max($item->reorder_quantity ?? $shortfall, $shortfall);
            $orderValue = $suggestedOrder * ($item->average_cost ?? 0);

            $entry = [
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'product_name' => $item->product_name,
                'category' => $item->category_name ?? 'Uncategorized',
                'warehouse_id' => $item->warehouse_id,
                'warehouse' => $item->warehouse_name,
                'unit' => $item->unit,
                'current_stock' => (float) $item->quantity,
                'available' => (float) $availableQty,
                'reorder_level' => (float) $item->reorder_level,
                'shortfall' => (float) $shortfall,
                'suggested_order' => (float) $suggestedOrder,
                'estimated_cost' => (float) $orderValue,
                'stock_percentage' => $item->reorder_level > 0
                    ? round(($item->quantity / $item->reorder_level) * 100, 1)
                    : 0,
            ];

            // Critical if stock is below 25% of reorder level or zero
            if ($item->quantity <= 0 || ($item->quantity / $item->reorder_level) < 0.25) {
                $entry['severity'] = 'critical';
                $criticalItems[] = $entry;
            } else {
                $entry['severity'] = 'low';
                $lowItems[] = $entry;
            }

            $totalReorderValue += $orderValue;
        }

        return [
            'report_type' => 'low_stock',
            'as_of_date' => now()->toDateString(),
            'filters' => [
                'warehouse_id' => $warehouseId,
            ],
            'critical_items' => $criticalItems,
            'low_items' => $lowItems,
            'summary' => [
                'critical_count' => count($criticalItems),
                'low_count' => count($lowItems),
                'total_items' => count($items),
                'total_reorder_value' => (float) $totalReorderValue,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Inventory Turnover Report.
     */
    public function generateInventoryTurnover(string $startDate, string $endDate): array
    {
        // Get average inventory value for the period
        $avgInventory = DB::table('stock_levels')
            ->where('organization_id', $this->organizationId)
            ->selectRaw('AVG(quantity * average_cost) as avg_value')
            ->value('avg_value') ?? 0;

        // Get COGS for the period
        $cogs = DB::table('document_lines as dl')
            ->join('invoices as i', function ($join) {
                $join->on('dl.document_id', '=', 'i.id')
                    ->where('dl.document_type', '=', 'invoice');
            })
            ->where('i.organization_id', $this->organizationId)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$startDate, $endDate])
            ->join('products as p', 'dl.product_id', '=', 'p.id')
            ->where('p.type', 'goods')
            ->selectRaw('SUM(dl.quantity * COALESCE(p.purchase_price, 0)) as cogs')
            ->value('cogs') ?? 0;

        // Calculate turnover metrics
        $turnoverRatio = $avgInventory > 0 ? $cogs / $avgInventory : 0;
        $periodDays = \Carbon\Carbon::parse($startDate)->diffInDays($endDate) + 1;
        $daysInInventory = $turnoverRatio > 0 ? $periodDays / $turnoverRatio : 0;

        // Get turnover by category
        $byCategory = DB::table('document_lines as dl')
            ->join('invoices as i', function ($join) {
                $join->on('dl.document_id', '=', 'i.id')
                    ->where('dl.document_type', '=', 'invoice');
            })
            ->join('products as p', 'dl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->where('i.organization_id', $this->organizationId)
            ->whereIn('i.status', ['sent', 'partial', 'paid'])
            ->whereBetween('i.invoice_date', [$startDate, $endDate])
            ->where('p.type', 'goods')
            ->groupBy('c.id', 'c.name')
            ->selectRaw('c.name as category, SUM(dl.quantity) as units_sold, SUM(dl.total) as revenue')
            ->orderByDesc('revenue')
            ->get()
            ->toArray();

        return [
            'report_type' => 'inventory_turnover',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'period_days' => $periodDays,
            'metrics' => [
                'average_inventory_value' => (float) $avgInventory,
                'cost_of_goods_sold' => (float) $cogs,
                'turnover_ratio' => round((float) $turnoverRatio, 2),
                'days_in_inventory' => round((float) $daysInInventory, 1),
                'annual_turnover' => round((float) ($turnoverRatio * (365 / $periodDays)), 2),
            ],
            'by_category' => $byCategory,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Batch/Expiry Report.
     */
    public function generateExpiryReport(int $daysAhead = 90, ?int $warehouseId = null): array
    {
        $today = now()->toDateString();
        $futureDate = now()->addDays($daysAhead)->toDateString();

        $query = DB::table('product_batches as pb')
            ->join('products as p', 'pb.product_id', '=', 'p.id')
            ->join('warehouses as w', 'pb.warehouse_id', '=', 'w.id')
            ->leftJoin('units_of_measure as u', 'p.unit_id', '=', 'u.id')
            ->where('pb.organization_id', $this->organizationId)
            ->where('pb.quantity', '>', 0)
            ->whereNotNull('pb.expiry_date')
            ->where('pb.expiry_date', '<=', $futureDate)
            ->orderBy('pb.expiry_date');

        if ($warehouseId) {
            $query->where('pb.warehouse_id', $warehouseId);
        }

        $batches = $query->select([
            'pb.id',
            'pb.batch_number',
            'pb.expiry_date',
            'pb.quantity',
            'pb.unit_cost',
            'p.id as product_id',
            'p.sku',
            'p.name as product_name',
            'w.name as warehouse_name',
            'u.symbol as unit',
        ])->get();

        $expired = [];
        $expiringSoon = [];
        $expiringLater = [];

        foreach ($batches as $batch) {
            $expiryDate = \Carbon\Carbon::parse($batch->expiry_date);
            $daysUntilExpiry = now()->startOfDay()->diffInDays($expiryDate, false);
            $value = $batch->quantity * ($batch->unit_cost ?? 0);

            $entry = [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'product_id' => $batch->product_id,
                'sku' => $batch->sku,
                'product_name' => $batch->product_name,
                'warehouse' => $batch->warehouse_name,
                'quantity' => (float) $batch->quantity,
                'unit' => $batch->unit,
                'unit_cost' => (float) ($batch->unit_cost ?? 0),
                'total_value' => (float) $value,
                'expiry_date' => $batch->expiry_date,
                'days_until_expiry' => $daysUntilExpiry,
            ];

            if ($daysUntilExpiry < 0) {
                $entry['status'] = 'expired';
                $expired[] = $entry;
            } elseif ($daysUntilExpiry <= 30) {
                $entry['status'] = 'expiring_soon';
                $expiringSoon[] = $entry;
            } else {
                $entry['status'] = 'expiring_later';
                $expiringLater[] = $entry;
            }
        }

        return [
            'report_type' => 'batch_expiry',
            'as_of_date' => $today,
            'days_ahead' => $daysAhead,
            'filters' => [
                'warehouse_id' => $warehouseId,
            ],
            'expired' => $expired,
            'expiring_soon' => $expiringSoon,
            'expiring_later' => $expiringLater,
            'summary' => [
                'expired_count' => count($expired),
                'expired_value' => collect($expired)->sum('total_value'),
                'expiring_soon_count' => count($expiringSoon),
                'expiring_soon_value' => collect($expiringSoon)->sum('total_value'),
                'expiring_later_count' => count($expiringLater),
                'expiring_later_value' => collect($expiringLater)->sum('total_value'),
                'total_at_risk' => collect([...$expired, ...$expiringSoon])->sum('total_value'),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get unit cost based on valuation method.
     */
    protected function getUnitCost(object $stock, string $method): float
    {
        return match ($method) {
            'last_purchase' => (float) ($stock->last_purchase_price ?? $stock->average_cost ?? 0),
            'weighted_average', 'average' => (float) ($stock->average_cost ?? 0),
            default => (float) ($stock->average_cost ?? 0),
        };
    }

    /**
     * Get movement type label.
     */
    protected function getMovementTypeLabel(string $type): string
    {
        return match ($type) {
            'in' => 'Stock In',
            'out' => 'Stock Out',
            'purchase' => 'Purchase Receipt',
            'sale' => 'Sales Delivery',
            'return_in' => 'Sales Return',
            'return_out' => 'Purchase Return',
            'adjustment_in' => 'Adjustment (Increase)',
            'adjustment_out' => 'Adjustment (Decrease)',
            'transfer_in' => 'Transfer In',
            'transfer_out' => 'Transfer Out',
            'production_in' => 'Production Output',
            'production_out' => 'Production Input',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}

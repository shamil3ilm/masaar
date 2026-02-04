<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Stock Valuation Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 10mm; }

        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #7c3aed; padding-bottom: 15px; }
        .company-name { font-size: 18px; font-weight: bold; color: #7c3aed; }
        .report-title { font-size: 16px; font-weight: bold; color: #333; margin-top: 5px; }
        .report-date { font-size: 11px; color: #666; margin-top: 5px; }

        .summary-cards { display: table; width: 100%; margin-bottom: 20px; }
        .card { display: table-cell; text-align: center; padding: 15px; background: #f5f3ff; border: 1px solid #ddd6fe; }
        .card:first-child { border-radius: 8px 0 0 8px; }
        .card:last-child { border-radius: 0 8px 8px 0; }
        .card-value { font-size: 18px; font-weight: bold; color: #7c3aed; }
        .card-label { font-size: 9px; color: #666; margin-top: 3px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th {
            background: #7c3aed;
            color: #fff;
            padding: 8px 5px;
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
        }
        th.text-right { text-align: right; }
        td { padding: 6px 5px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
        td.text-right { text-align: right; font-family: monospace; }
        tr:nth-child(even) { background: #faf5ff; }

        .category-header {
            background: #ede9fe;
            font-weight: bold;
            color: #5b21b6;
        }
        .category-header td { padding: 8px 5px; border-bottom: 2px solid #c4b5fd; }

        .total-row { background: #ddd6fe !important; font-weight: bold; }
        .total-row td { border-top: 2px solid #7c3aed; padding: 10px 5px; }

        .breakdown-section { margin-top: 20px; }
        .breakdown-title { font-weight: bold; font-size: 11px; color: #7c3aed; margin-bottom: 10px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        .breakdown-grid { display: table; width: 100%; }
        .breakdown-item { display: table-cell; width: 50%; padding: 10px; }
        .breakdown-table { width: 100%; border-collapse: collapse; }
        .breakdown-table th { background: #ede9fe; color: #5b21b6; padding: 6px; font-size: 8px; }
        .breakdown-table td { padding: 5px; font-size: 9px; border-bottom: 1px solid #e5e7eb; }

        .footer { margin-top: 30px; text-align: center; font-size: 8px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }

        @page { margin: 10mm; }
        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            @if(isset($organization) && $organization->name)
            <div class="company-name">{{ $organization->name }}</div>
            @endif
            <div class="report-title">Stock Valuation Report</div>
            <div class="report-date">As of {{ $as_of_date ?? now()->format('d M Y') }}</div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="card">
                <div class="card-value">{{ $summary['product_count'] ?? count($items ?? []) }}</div>
                <div class="card-label">Products</div>
            </div>
            <div class="card">
                <div class="card-value">{{ number_format((float)($summary['total_quantity'] ?? 0), 0) }}</div>
                <div class="card-label">Total Units</div>
            </div>
            <div class="card">
                <div class="card-value">{{ number_format((float)($summary['total_value'] ?? 0), 0) }}</div>
                <div class="card-label">Total Value</div>
            </div>
            <div class="card">
                <div class="card-value">{{ number_format((float)($summary['average_item_value'] ?? 0), 2) }}</div>
                <div class="card-label">Avg Item Value</div>
            </div>
        </div>

        <!-- Stock Table -->
        <table>
            <thead>
                <tr>
                    <th style="width: 12%">SKU</th>
                    <th style="width: 25%">Product</th>
                    <th style="width: 12%">Category</th>
                    <th style="width: 12%">Warehouse</th>
                    <th class="text-right" style="width: 10%">Qty</th>
                    <th class="text-right" style="width: 10%">Available</th>
                    <th class="text-right" style="width: 10%">Unit Cost</th>
                    <th class="text-right" style="width: 12%">Total Value</th>
                </tr>
            </thead>
            <tbody>
                @php $currentCategory = null; @endphp
                @foreach($items ?? [] as $item)
                    @if(($item['category'] ?? 'Uncategorized') !== $currentCategory)
                        @php $currentCategory = $item['category'] ?? 'Uncategorized'; @endphp
                        <tr class="category-header">
                            <td colspan="8">{{ $currentCategory }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td>{{ $item['sku'] ?? '-' }}</td>
                        <td>{{ $item['product_name'] ?? '' }}</td>
                        <td>{{ $item['category'] ?? 'Uncategorized' }}</td>
                        <td>{{ $item['warehouse'] ?? '' }}</td>
                        <td class="text-right">{{ number_format((float)($item['quantity'] ?? 0), 2) }} {{ $item['unit'] ?? '' }}</td>
                        <td class="text-right">{{ number_format((float)($item['available'] ?? 0), 2) }}</td>
                        <td class="text-right">{{ number_format((float)($item['unit_cost'] ?? 0), 2) }}</td>
                        <td class="text-right">{{ number_format((float)($item['total_value'] ?? 0), 2) }}</td>
                    </tr>
                @endforeach

                <!-- Totals Row -->
                <tr class="total-row">
                    <td colspan="4">TOTAL</td>
                    <td class="text-right">{{ number_format((float)($summary['total_quantity'] ?? 0), 2) }}</td>
                    <td class="text-right">-</td>
                    <td class="text-right">-</td>
                    <td class="text-right">{{ number_format((float)($summary['total_value'] ?? 0), 2) }}</td>
                </tr>
            </tbody>
        </table>

        <!-- Breakdown Section -->
        @if((isset($by_category) && count($by_category)) || (isset($by_warehouse) && count($by_warehouse)))
        <div class="breakdown-section">
            <div class="breakdown-title">Breakdown</div>
            <div class="breakdown-grid">
                @if(isset($by_category) && count($by_category))
                <div class="breakdown-item">
                    <table class="breakdown-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_category as $category => $data)
                            <tr>
                                <td>{{ $category }}</td>
                                <td class="text-right">{{ number_format((float)$data['quantity'], 0) }}</td>
                                <td class="text-right">{{ number_format((float)$data['value'], 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

                @if(isset($by_warehouse) && count($by_warehouse))
                <div class="breakdown-item">
                    <table class="breakdown-table">
                        <thead>
                            <tr>
                                <th>Warehouse</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_warehouse as $warehouse => $data)
                            <tr>
                                <td>{{ $warehouse }}</td>
                                <td class="text-right">{{ number_format((float)$data['quantity'], 0) }}</td>
                                <td class="text-right">{{ number_format((float)$data['value'], 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            @if(isset($organization) && $organization->name)
            {{ $organization->name }}
            <br>
            @endif
            Generated: {{ $generated_at ?? now()->format('d M Y H:i:s') }}
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales by Customer Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 10mm; }

        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #059669; padding-bottom: 15px; }
        .company-name { font-size: 18px; font-weight: bold; color: #059669; }
        .report-title { font-size: 16px; font-weight: bold; color: #333; margin-top: 5px; }
        .report-period { font-size: 11px; color: #666; margin-top: 5px; }

        .summary-cards { display: table; width: 100%; margin-bottom: 20px; }
        .card { display: table-cell; text-align: center; padding: 15px; background: #f0fdf4; border: 1px solid #bbf7d0; }
        .card:first-child { border-radius: 8px 0 0 8px; }
        .card:last-child { border-radius: 0 8px 8px 0; }
        .card-value { font-size: 18px; font-weight: bold; color: #059669; }
        .card-label { font-size: 9px; color: #666; margin-top: 3px; }

        table { width: 100%; border-collapse: collapse; }
        th {
            background: #059669;
            color: #fff;
            padding: 10px 6px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
        }
        th.text-right { text-align: right; }
        th.text-center { text-align: center; }
        td { padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
        td.text-right { text-align: right; font-family: monospace; }
        td.text-center { text-align: center; }
        tr:nth-child(even) { background: #f9fafb; }

        .rank-badge {
            display: inline-block;
            width: 20px;
            height: 20px;
            line-height: 20px;
            text-align: center;
            border-radius: 50%;
            font-weight: bold;
            font-size: 8px;
        }
        .rank-1 { background: #fef3c7; color: #b45309; }
        .rank-2 { background: #e5e7eb; color: #374151; }
        .rank-3 { background: #fed7aa; color: #c2410c; }
        .rank-default { background: #f3f4f6; color: #6b7280; }

        .percentage-bar {
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }
        .percentage-fill {
            height: 100%;
            background: #059669;
            border-radius: 3px;
        }

        .total-row {
            background: #dcfce7 !important;
            font-weight: bold;
        }
        .total-row td { border-top: 2px solid #059669; }

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
            <div class="report-title">Sales by Customer Report</div>
            <div class="report-period">{{ $period_start ?? '' }} to {{ $period_end ?? '' }}</div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="card">
                <div class="card-value">{{ $summary['customer_count'] ?? 0 }}</div>
                <div class="card-label">Customers</div>
            </div>
            <div class="card">
                <div class="card-value">{{ $summary['total_invoices'] ?? 0 }}</div>
                <div class="card-label">Invoices</div>
            </div>
            <div class="card">
                <div class="card-value">{{ number_format((float)($summary['total_sales'] ?? 0), 0) }}</div>
                <div class="card-label">Total Sales</div>
            </div>
            <div class="card">
                <div class="card-value">{{ number_format((float)($summary['total_paid'] ?? 0), 0) }}</div>
                <div class="card-label">Collected</div>
            </div>
            <div class="card">
                <div class="card-value">{{ $summary['collection_rate'] ?? 0 }}%</div>
                <div class="card-label">Collection Rate</div>
            </div>
        </div>

        <!-- Customer Table -->
        <table>
            <thead>
                <tr>
                    <th style="width: 5%">#</th>
                    <th style="width: 25%">Customer</th>
                    <th class="text-center" style="width: 8%">Invoices</th>
                    <th class="text-right" style="width: 12%">Subtotal</th>
                    <th class="text-right" style="width: 10%">Tax</th>
                    <th class="text-right" style="width: 12%">Total</th>
                    <th class="text-right" style="width: 10%">Paid</th>
                    <th class="text-right" style="width: 10%">Due</th>
                    <th style="width: 8%">Share</th>
                </tr>
            </thead>
            <tbody>
                @foreach($customers ?? [] as $customer)
                <tr>
                    <td>
                        @php $rank = $customer['rank'] ?? $loop->iteration; @endphp
                        <span class="rank-badge rank-{{ $rank <= 3 ? $rank : 'default' }}">{{ $rank }}</span>
                    </td>
                    <td>{{ $customer['customer_name'] ?? 'Unknown' }}</td>
                    <td class="text-center">{{ $customer['invoice_count'] ?? 0 }}</td>
                    <td class="text-right">{{ number_format((float)($customer['subtotal'] ?? 0), 2) }}</td>
                    <td class="text-right">{{ number_format((float)($customer['tax'] ?? 0), 2) }}</td>
                    <td class="text-right">{{ number_format((float)($customer['total'] ?? 0), 2) }}</td>
                    <td class="text-right">{{ number_format((float)($customer['paid'] ?? 0), 2) }}</td>
                    <td class="text-right">{{ number_format((float)($customer['outstanding'] ?? 0), 2) }}</td>
                    <td>
                        <div class="percentage-bar">
                            <div class="percentage-fill" style="width: {{ min($customer['percentage_of_total'] ?? 0, 100) }}%;"></div>
                        </div>
                        <div style="font-size: 8px; text-align: center; margin-top: 2px;">{{ $customer['percentage_of_total'] ?? 0 }}%</div>
                    </td>
                </tr>
                @endforeach

                <!-- Totals Row -->
                <tr class="total-row">
                    <td colspan="2">TOTAL</td>
                    <td class="text-center">{{ $summary['total_invoices'] ?? 0 }}</td>
                    <td class="text-right">-</td>
                    <td class="text-right">-</td>
                    <td class="text-right">{{ number_format((float)($summary['total_sales'] ?? 0), 2) }}</td>
                    <td class="text-right">{{ number_format((float)($summary['total_paid'] ?? 0), 2) }}</td>
                    <td class="text-right">{{ number_format((float)($summary['total_outstanding'] ?? 0), 2) }}</td>
                    <td>100%</td>
                </tr>
            </tbody>
        </table>

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

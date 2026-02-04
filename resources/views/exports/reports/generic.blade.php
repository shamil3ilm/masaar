<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report_title ?? 'Report' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 10mm; }

        .header { margin-bottom: 20px; border-bottom: 2px solid #2563eb; padding-bottom: 15px; }
        .company-name { font-size: 16px; font-weight: bold; color: #1e40af; }
        .report-title { font-size: 20px; font-weight: bold; color: #333; margin-top: 10px; }
        .report-meta { font-size: 9px; color: #666; margin-top: 5px; }

        .section { margin-bottom: 20px; }
        .section-title { font-size: 12px; font-weight: bold; color: #1e40af; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 10px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th {
            background: #2563eb;
            color: #fff;
            padding: 8px 6px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
        }
        td { padding: 6px; border-bottom: 1px solid #eee; font-size: 9px; }
        tr:nth-child(even) { background: #f9fafb; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .total-row { background: #dbeafe !important; font-weight: bold; }
        .total-row td { border-top: 2px solid #2563eb; }

        .summary-box { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 4px; padding: 15px; margin-bottom: 20px; }
        .summary-title { font-weight: bold; font-size: 11px; color: #0369a1; margin-bottom: 10px; }
        .summary-grid { display: table; width: 100%; }
        .summary-item { display: table-cell; text-align: center; padding: 5px; }
        .summary-value { font-size: 14px; font-weight: bold; color: #1e40af; }
        .summary-label { font-size: 8px; color: #666; }

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
            <div class="report-title">{{ $report_title ?? 'Report' }}</div>
            <div class="report-meta">
                @if(isset($period_start) && isset($period_end))
                    Period: {{ $period_start }} to {{ $period_end }}
                @elseif(isset($as_of_date))
                    As of: {{ $as_of_date }}
                @endif
                | Generated: {{ now()->format('d M Y H:i') }}
            </div>
        </div>

        <!-- Summary -->
        @if(isset($summary) && is_array($summary))
        <div class="summary-box">
            <div class="summary-title">Summary</div>
            <div class="summary-grid">
                @foreach($summary as $key => $value)
                    @if(!is_array($value))
                    <div class="summary-item">
                        <div class="summary-value">
                            @if(is_numeric($value))
                                {{ number_format((float)$value, 2) }}
                            @else
                                {{ $value }}
                            @endif
                        </div>
                        <div class="summary-label">{{ ucwords(str_replace('_', ' ', $key)) }}</div>
                    </div>
                    @endif
                @endforeach
            </div>
        </div>
        @endif

        <!-- Main Data -->
        @php
            // Find the main data array
            $items = null;
            $dataKeys = ['items', 'accounts', 'customers', 'products', 'movements', 'salespeople', 'periods'];
            foreach ($dataKeys as $key) {
                if (isset($$key) && is_array($$key)) {
                    $items = $$key;
                    break;
                }
            }
        @endphp

        @if($items && count($items) > 0)
        <div class="section">
            <table>
                <thead>
                    <tr>
                        @php $firstItem = reset($items); @endphp
                        @if(is_array($firstItem))
                            @foreach(array_keys($firstItem) as $header)
                                @if(!is_array($firstItem[$header]))
                                <th>{{ ucwords(str_replace('_', ' ', $header)) }}</th>
                                @endif
                            @endforeach
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                    <tr>
                        @if(is_array($item))
                            @foreach($item as $key => $value)
                                @if(!is_array($value))
                                <td class="{{ in_array($key, ['total', 'amount', 'balance', 'debit', 'credit', 'quantity', 'value', 'cost', 'paid', 'outstanding']) ? 'text-right' : '' }}">
                                    @if(is_numeric($value) && !in_array($key, ['id', 'rank', 'invoice_count', 'product_count', 'customer_count']))
                                        {{ number_format((float)$value, 2) }}
                                    @else
                                        {{ $value }}
                                    @endif
                                </td>
                                @endif
                            @endforeach
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="section">
            <p style="text-align: center; color: #666; padding: 30px;">No data available for this report.</p>
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            @if(isset($organization) && $organization->name)
            {{ $organization->name }}
            @if($organization->website ?? null) | {{ $organization->website }} @endif
            <br>
            @endif
            Report generated on {{ now()->format('d M Y H:i:s') }}
        </div>
    </div>
</body>
</html>

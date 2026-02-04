<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Turnover Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 10mm; }

        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #dc2626; padding-bottom: 15px; }
        .company-name { font-size: 18px; font-weight: bold; color: #dc2626; }
        .report-title { font-size: 16px; font-weight: bold; color: #333; margin-top: 5px; }
        .report-period { font-size: 11px; color: #666; margin-top: 5px; }

        .kpi-grid { display: table; width: 100%; margin-bottom: 20px; }
        .kpi-item {
            display: table-cell;
            text-align: center;
            padding: 15px;
            border: 1px solid #e5e7eb;
        }
        .kpi-value { font-size: 28px; font-weight: bold; }
        .kpi-label { font-size: 9px; color: #666; margin-top: 5px; text-transform: uppercase; }
        .kpi-positive { color: #059669; }
        .kpi-negative { color: #dc2626; }
        .kpi-neutral { color: #1e40af; }

        .metrics-row { display: table; width: 100%; margin-bottom: 20px; }
        .metric-box {
            display: table-cell;
            width: 33.33%;
            padding: 15px;
            text-align: center;
        }
        .metric-box.hires { background: #dcfce7; border: 1px solid #bbf7d0; }
        .metric-box.separations { background: #fee2e2; border: 1px solid #fecaca; }
        .metric-box.net { background: #dbeafe; border: 1px solid #bfdbfe; }
        .metric-value { font-size: 24px; font-weight: bold; }
        .metric-label { font-size: 10px; margin-top: 5px; }

        .section { margin-bottom: 20px; }
        .section-title {
            background: #374151;
            color: #fff;
            padding: 8px 10px;
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f3f4f6;
            padding: 8px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            border-bottom: 2px solid #d1d5db;
        }
        th.text-right, td.text-right { text-align: right; }
        td { padding: 8px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
        tr:nth-child(even) { background: #f9fafb; }

        .two-column { display: table; width: 100%; }
        .column { display: table-cell; width: 50%; vertical-align: top; padding: 0 10px; }
        .column:first-child { padding-left: 0; }
        .column:last-child { padding-right: 0; }

        .trend-indicator {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
        }
        .trend-up { background: #fee2e2; color: #991b1b; }
        .trend-down { background: #dcfce7; color: #166534; }
        .trend-stable { background: #f3f4f6; color: #374151; }

        .insights-box {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 4px;
            padding: 15px;
            margin-top: 20px;
        }
        .insights-title { font-weight: bold; color: #92400e; margin-bottom: 10px; }
        .insights-list { margin-left: 15px; }
        .insights-list li { margin: 5px 0; color: #78350f; }

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
            <div class="report-title">Employee Turnover Report</div>
            <div class="report-period">{{ $start_date ?? '' }} to {{ $end_date ?? '' }}</div>
        </div>

        <!-- KPIs -->
        <div class="kpi-grid">
            <div class="kpi-item">
                <div class="kpi-value kpi-negative">{{ number_format($summary['turnover_rate'] ?? 0, 1) }}%</div>
                <div class="kpi-label">Turnover Rate</div>
            </div>
            <div class="kpi-item">
                <div class="kpi-value kpi-negative">{{ number_format($summary['attrition_rate'] ?? 0, 1) }}%</div>
                <div class="kpi-label">Attrition Rate</div>
            </div>
            <div class="kpi-item">
                <div class="kpi-value kpi-neutral">{{ number_format($summary['retention_rate'] ?? 0, 1) }}%</div>
                <div class="kpi-label">Retention Rate</div>
            </div>
            <div class="kpi-item">
                <div class="kpi-value kpi-neutral">{{ $summary['avg_tenure_months'] ?? 0 }}</div>
                <div class="kpi-label">Avg Tenure (Months)</div>
            </div>
        </div>

        <!-- Hires / Separations / Net -->
        <div class="metrics-row">
            <div class="metric-box hires">
                <div class="metric-value" style="color: #166534;">+{{ $summary['total_hires'] ?? 0 }}</div>
                <div class="metric-label">New Hires</div>
            </div>
            <div class="metric-box separations">
                <div class="metric-value" style="color: #991b1b;">-{{ $summary['total_separations'] ?? 0 }}</div>
                <div class="metric-label">Separations</div>
            </div>
            <div class="metric-box net">
                @php
                    $netChange = ($summary['total_hires'] ?? 0) - ($summary['total_separations'] ?? 0);
                @endphp
                <div class="metric-value" style="color: {{ $netChange >= 0 ? '#166534' : '#991b1b' }};">
                    {{ $netChange >= 0 ? '+' : '' }}{{ $netChange }}
                </div>
                <div class="metric-label">Net Change</div>
            </div>
        </div>

        <div class="two-column">
            <!-- By Department -->
            <div class="column">
                <div class="section">
                    <div class="section-title">Turnover by Department</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th class="text-right">Hires</th>
                                <th class="text-right">Left</th>
                                <th class="text-right">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_department ?? [] as $dept)
                            <tr>
                                <td>{{ $dept['name'] ?? 'Unknown' }}</td>
                                <td class="text-right" style="color: #166534;">{{ $dept['hires'] ?? 0 }}</td>
                                <td class="text-right" style="color: #991b1b;">{{ $dept['separations'] ?? 0 }}</td>
                                <td class="text-right">
                                    <span class="trend-indicator {{ ($dept['turnover_rate'] ?? 0) > 15 ? 'trend-up' : (($dept['turnover_rate'] ?? 0) < 5 ? 'trend-down' : 'trend-stable') }}">
                                        {{ number_format($dept['turnover_rate'] ?? 0, 1) }}%
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- By Reason -->
            <div class="column">
                <div class="section">
                    <div class="section-title">Separation Reasons</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Reason</th>
                                <th class="text-right">Count</th>
                                <th class="text-right">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_reason ?? [] as $reason)
                            <tr>
                                <td>{{ $reason['reason'] ?? 'Unknown' }}</td>
                                <td class="text-right">{{ $reason['count'] ?? 0 }}</td>
                                <td class="text-right">{{ number_format($reason['percentage'] ?? 0, 1) }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Monthly Trend -->
        @if(isset($monthly_trend) && count($monthly_trend) > 0)
        <div class="section">
            <div class="section-title">Monthly Trend</div>
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="text-right">Opening</th>
                        <th class="text-right">Hires</th>
                        <th class="text-right">Separations</th>
                        <th class="text-right">Closing</th>
                        <th class="text-right">Turnover %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($monthly_trend as $month)
                    <tr>
                        <td>{{ $month['month'] ?? '' }}</td>
                        <td class="text-right">{{ $month['opening'] ?? 0 }}</td>
                        <td class="text-right" style="color: #166534;">+{{ $month['hires'] ?? 0 }}</td>
                        <td class="text-right" style="color: #991b1b;">-{{ $month['separations'] ?? 0 }}</td>
                        <td class="text-right">{{ $month['closing'] ?? 0 }}</td>
                        <td class="text-right">{{ number_format($month['turnover_rate'] ?? 0, 1) }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <!-- Insights -->
        @if(isset($insights) && count($insights) > 0)
        <div class="insights-box">
            <div class="insights-title">Key Insights</div>
            <ul class="insights-list">
                @foreach($insights as $insight)
                <li>{{ $insight }}</li>
                @endforeach
            </ul>
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

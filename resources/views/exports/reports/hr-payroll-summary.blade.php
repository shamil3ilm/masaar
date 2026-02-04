<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payroll Summary Report</title>
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
        .report-period { font-size: 11px; color: #666; margin-top: 5px; }

        .totals-grid { display: table; width: 100%; margin-bottom: 20px; }
        .total-item {
            display: table-cell;
            text-align: center;
            padding: 15px;
            border: 1px solid #e5e7eb;
        }
        .total-item.gross { background: #f0fdf4; border-color: #bbf7d0; }
        .total-item.deductions { background: #fef2f2; border-color: #fecaca; }
        .total-item.net { background: #ede9fe; border-color: #c4b5fd; }
        .total-item.employer { background: #fef3c7; border-color: #fcd34d; }
        .total-value { font-size: 18px; font-weight: bold; }
        .total-label { font-size: 9px; color: #666; margin-top: 5px; text-transform: uppercase; }

        .section { margin-bottom: 20px; }
        .section-title {
            background: #7c3aed;
            color: #fff;
            padding: 8px 10px;
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .section-title.green { background: #059669; }
        .section-title.red { background: #dc2626; }

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
        .amount { font-family: monospace; }

        .total-row { background: #ede9fe !important; font-weight: bold; }
        .total-row td { border-top: 2px solid #7c3aed; }

        .two-column { display: table; width: 100%; }
        .column { display: table-cell; width: 50%; vertical-align: top; padding: 0 10px; }
        .column:first-child { padding-left: 0; }
        .column:last-child { padding-right: 0; }

        .stat-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .stat-label { font-size: 9px; color: #6b7280; text-transform: uppercase; }
        .stat-value { font-size: 14px; font-weight: bold; color: #374151; margin-top: 3px; }

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
            <div class="report-title">Payroll Summary Report</div>
            <div class="report-period">{{ $start_date ?? '' }} to {{ $end_date ?? '' }}</div>
        </div>

        <!-- Totals -->
        <div class="totals-grid">
            <div class="total-item gross">
                <div class="total-value" style="color: #166534;">{{ $currency_code ?? '' }} {{ number_format($summary['total_gross'] ?? 0, 2) }}</div>
                <div class="total-label">Total Gross</div>
            </div>
            <div class="total-item deductions">
                <div class="total-value" style="color: #991b1b;">{{ $currency_code ?? '' }} {{ number_format($summary['total_deductions'] ?? 0, 2) }}</div>
                <div class="total-label">Total Deductions</div>
            </div>
            <div class="total-item net">
                <div class="total-value" style="color: #5b21b6;">{{ $currency_code ?? '' }} {{ number_format($summary['total_net'] ?? 0, 2) }}</div>
                <div class="total-label">Total Net Pay</div>
            </div>
            <div class="total-item employer">
                <div class="total-value" style="color: #92400e;">{{ $currency_code ?? '' }} {{ number_format($summary['employer_contributions'] ?? 0, 2) }}</div>
                <div class="total-label">Employer Contributions</div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="two-column" style="margin-bottom: 20px;">
            <div class="column">
                <div class="stat-box">
                    <div class="stat-label">Employees Processed</div>
                    <div class="stat-value">{{ $summary['employee_count'] ?? 0 }}</div>
                </div>
            </div>
            <div class="column">
                <div class="stat-box">
                    <div class="stat-label">Average Salary</div>
                    <div class="stat-value">{{ $currency_code ?? '' }} {{ number_format($summary['average_salary'] ?? 0, 2) }}</div>
                </div>
            </div>
        </div>

        <!-- By Department -->
        <div class="section">
            <div class="section-title">Payroll by Department</div>
            <table>
                <thead>
                    <tr>
                        <th>Department</th>
                        <th class="text-right">Employees</th>
                        <th class="text-right">Gross</th>
                        <th class="text-right">Deductions</th>
                        <th class="text-right">Net Pay</th>
                        <th class="text-right">Avg Salary</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($by_department ?? [] as $dept)
                    <tr>
                        <td>{{ $dept['name'] ?? 'Unassigned' }}</td>
                        <td class="text-right">{{ $dept['employee_count'] ?? 0 }}</td>
                        <td class="text-right amount">{{ number_format($dept['gross'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format($dept['deductions'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format($dept['net'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format($dept['average'] ?? 0, 2) }}</td>
                    </tr>
                    @endforeach
                    <tr class="total-row">
                        <td>TOTAL</td>
                        <td class="text-right">{{ $summary['employee_count'] ?? 0 }}</td>
                        <td class="text-right amount">{{ number_format($summary['total_gross'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format($summary['total_deductions'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format($summary['total_net'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format($summary['average_salary'] ?? 0, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="two-column">
            <!-- Earnings Breakdown -->
            <div class="column">
                <div class="section">
                    <div class="section-title green">Earnings Breakdown</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Component</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_component['earnings'] ?? [] as $comp)
                            <tr>
                                <td>{{ $comp['name'] ?? '' }}</td>
                                <td class="text-right amount">{{ number_format($comp['amount'] ?? 0, 2) }}</td>
                                <td class="text-right">{{ number_format($comp['percentage'] ?? 0, 1) }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Deductions Breakdown -->
            <div class="column">
                <div class="section">
                    <div class="section-title red">Deductions Breakdown</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Component</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_component['deductions'] ?? [] as $comp)
                            <tr>
                                <td>{{ $comp['name'] ?? '' }}</td>
                                <td class="text-right amount">{{ number_format($comp['amount'] ?? 0, 2) }}</td>
                                <td class="text-right">{{ number_format($comp['percentage'] ?? 0, 1) }}%</td>
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
            <div class="section-title">Monthly Payroll Trend</div>
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="text-right">Employees</th>
                        <th class="text-right">Gross</th>
                        <th class="text-right">Deductions</th>
                        <th class="text-right">Net Pay</th>
                        <th class="text-right">Change</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($monthly_trend as $month)
                    <tr>
                        <td>{{ $month['month'] ?? '' }}</td>
                        <td class="text-right">{{ $month['employee_count'] ?? 0 }}</td>
                        <td class="text-right amount">{{ number_format($month['gross'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format($month['deductions'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format($month['net'] ?? 0, 2) }}</td>
                        <td class="text-right" style="color: {{ ($month['change_percent'] ?? 0) >= 0 ? '#166534' : '#991b1b' }}">
                            {{ ($month['change_percent'] ?? 0) >= 0 ? '+' : '' }}{{ number_format($month['change_percent'] ?? 0, 1) }}%
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <!-- Statutory Summary -->
        @if(isset($statutory_summary) && count($statutory_summary) > 0)
        <div class="section">
            <div class="section-title">Statutory Contributions Summary</div>
            <table>
                <thead>
                    <tr>
                        <th>Scheme</th>
                        <th class="text-right">Employee Share</th>
                        <th class="text-right">Employer Share</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($statutory_summary as $stat)
                    <tr>
                        <td>{{ $stat['name'] ?? '' }}</td>
                        <td class="text-right amount">{{ number_format($stat['employee_share'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format($stat['employer_share'] ?? 0, 2) }}</td>
                        <td class="text-right amount">{{ number_format(($stat['employee_share'] ?? 0) + ($stat['employer_share'] ?? 0), 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
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

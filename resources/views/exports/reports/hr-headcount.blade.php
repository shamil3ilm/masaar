<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Headcount Report</title>
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
        .report-date { font-size: 11px; color: #666; margin-top: 5px; }

        .summary-grid { display: table; width: 100%; margin-bottom: 20px; }
        .summary-item {
            display: table-cell;
            text-align: center;
            padding: 15px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
        }
        .summary-value { font-size: 24px; font-weight: bold; color: #059669; }
        .summary-label { font-size: 9px; color: #666; margin-top: 5px; text-transform: uppercase; }

        .section { margin-bottom: 20px; }
        .section-title {
            background: #059669;
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

        .chart-placeholder {
            background: #f9fafb;
            border: 1px dashed #d1d5db;
            padding: 30px;
            text-align: center;
            color: #9ca3af;
            margin-bottom: 15px;
        }

        .two-column { display: table; width: 100%; }
        .column { display: table-cell; width: 50%; vertical-align: top; padding: 0 10px; }
        .column:first-child { padding-left: 0; }
        .column:last-child { padding-right: 0; }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }

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
            <div class="report-title">Headcount Report</div>
            <div class="report-date">As of {{ $as_of_date ?? now()->format('d M Y') }}</div>
        </div>

        <!-- Summary -->
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">{{ $summary['total'] ?? 0 }}</div>
                <div class="summary-label">Total Employees</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ $summary['active'] ?? 0 }}</div>
                <div class="summary-label">Active</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ $summary['probation'] ?? 0 }}</div>
                <div class="summary-label">On Probation</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ $summary['notice'] ?? 0 }}</div>
                <div class="summary-label">On Notice</div>
            </div>
        </div>

        <div class="two-column">
            <!-- By Department -->
            <div class="column">
                <div class="section">
                    <div class="section-title">By Department</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th class="text-right">Count</th>
                                <th class="text-right">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_department ?? [] as $dept)
                            <tr>
                                <td>{{ $dept['name'] ?? 'Unassigned' }}</td>
                                <td class="text-right">{{ $dept['count'] ?? 0 }}</td>
                                <td class="text-right">{{ number_format($dept['percentage'] ?? 0, 1) }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- By Designation -->
            <div class="column">
                <div class="section">
                    <div class="section-title">By Designation</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Designation</th>
                                <th class="text-right">Count</th>
                                <th class="text-right">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_designation ?? [] as $desg)
                            <tr>
                                <td>{{ $desg['name'] ?? 'Unassigned' }}</td>
                                <td class="text-right">{{ $desg['count'] ?? 0 }}</td>
                                <td class="text-right">{{ number_format($desg['percentage'] ?? 0, 1) }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="two-column">
            <!-- By Tenure -->
            <div class="column">
                <div class="section">
                    <div class="section-title">By Tenure</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Tenure</th>
                                <th class="text-right">Count</th>
                                <th class="text-right">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_tenure ?? [] as $tenure)
                            <tr>
                                <td>{{ $tenure['range'] ?? '' }}</td>
                                <td class="text-right">{{ $tenure['count'] ?? 0 }}</td>
                                <td class="text-right">{{ number_format($tenure['percentage'] ?? 0, 1) }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- By Age Group -->
            <div class="column">
                <div class="section">
                    <div class="section-title">By Age Group</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Age Group</th>
                                <th class="text-right">Count</th>
                                <th class="text-right">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_age ?? [] as $age)
                            <tr>
                                <td>{{ $age['range'] ?? '' }}</td>
                                <td class="text-right">{{ $age['count'] ?? 0 }}</td>
                                <td class="text-right">{{ number_format($age['percentage'] ?? 0, 1) }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if(isset($by_nationality) && count($by_nationality) > 0)
        <div class="section">
            <div class="section-title">By Nationality</div>
            <table>
                <thead>
                    <tr>
                        <th>Nationality</th>
                        <th class="text-right">Count</th>
                        <th class="text-right">Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($by_nationality as $nat)
                    <tr>
                        <td>{{ $nat['nationality'] ?? 'Unknown' }}</td>
                        <td class="text-right">{{ $nat['count'] ?? 0 }}</td>
                        <td class="text-right">{{ number_format($nat['percentage'] ?? 0, 1) }}%</td>
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

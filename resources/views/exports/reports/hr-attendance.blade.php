<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 10mm; }

        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0891b2; padding-bottom: 15px; }
        .company-name { font-size: 18px; font-weight: bold; color: #0891b2; }
        .report-title { font-size: 16px; font-weight: bold; color: #333; margin-top: 5px; }
        .report-period { font-size: 11px; color: #666; margin-top: 5px; }

        .stats-grid { display: table; width: 100%; margin-bottom: 20px; }
        .stat-item {
            display: table-cell;
            text-align: center;
            padding: 12px;
            border: 1px solid #e5e7eb;
        }
        .stat-item.present { background: #dcfce7; border-color: #bbf7d0; }
        .stat-item.late { background: #fef3c7; border-color: #fcd34d; }
        .stat-item.absent { background: #fee2e2; border-color: #fecaca; }
        .stat-item.leave { background: #dbeafe; border-color: #bfdbfe; }
        .stat-value { font-size: 20px; font-weight: bold; }
        .stat-label { font-size: 9px; color: #666; margin-top: 3px; text-transform: uppercase; }

        .attendance-rate {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%);
            color: #fff;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .rate-value { font-size: 36px; font-weight: bold; }
        .rate-label { font-size: 12px; opacity: 0.9; margin-top: 5px; }

        .section { margin-bottom: 20px; }
        .section-title {
            background: #0891b2;
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
        th.text-center, td.text-center { text-align: center; }
        td { padding: 8px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
        tr:nth-child(even) { background: #f9fafb; }

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
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }

        .progress-bar {
            background: #e5e7eb;
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: 4px;
        }
        .progress-fill.excellent { background: #22c55e; }
        .progress-fill.good { background: #eab308; }
        .progress-fill.poor { background: #ef4444; }

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
            <div class="report-title">Attendance Report</div>
            <div class="report-period">{{ $start_date ?? '' }} to {{ $end_date ?? '' }}</div>
        </div>

        <!-- Overall Attendance Rate -->
        <div class="attendance-rate">
            <div class="rate-value">{{ number_format($summary['attendance_rate'] ?? 0, 1) }}%</div>
            <div class="rate-label">Overall Attendance Rate</div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-item present">
                <div class="stat-value" style="color: #166534;">{{ $summary['total_present'] ?? 0 }}</div>
                <div class="stat-label">Present Days</div>
            </div>
            <div class="stat-item late">
                <div class="stat-value" style="color: #92400e;">{{ $summary['total_late'] ?? 0 }}</div>
                <div class="stat-label">Late Arrivals</div>
            </div>
            <div class="stat-item absent">
                <div class="stat-value" style="color: #991b1b;">{{ $summary['total_absent'] ?? 0 }}</div>
                <div class="stat-label">Absent Days</div>
            </div>
            <div class="stat-item leave">
                <div class="stat-value" style="color: #1e40af;">{{ $summary['total_leave'] ?? 0 }}</div>
                <div class="stat-label">Leave Days</div>
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
                                <th class="text-right">Present</th>
                                <th class="text-right">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_department ?? [] as $dept)
                            <tr>
                                <td>{{ $dept['name'] ?? 'Unknown' }}</td>
                                <td class="text-right">{{ $dept['present'] ?? 0 }}/{{ $dept['total'] ?? 0 }}</td>
                                <td class="text-right">
                                    @php $rate = $dept['attendance_rate'] ?? 0; @endphp
                                    <span class="badge {{ $rate >= 95 ? 'badge-success' : ($rate >= 85 ? 'badge-warning' : 'badge-danger') }}">
                                        {{ number_format($rate, 1) }}%
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- By Day of Week -->
            <div class="column">
                <div class="section">
                    <div class="section-title">By Day of Week</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th class="text-right">Avg Present</th>
                                <th class="text-right">Avg Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($by_day_of_week ?? [] as $day)
                            <tr>
                                <td>{{ $day['day'] ?? '' }}</td>
                                <td class="text-right">{{ number_format($day['avg_present'] ?? 0, 0) }}</td>
                                <td class="text-right">{{ number_format($day['avg_rate'] ?? 0, 1) }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Late Arrivals Analysis -->
        @if(isset($late_arrivals) && count($late_arrivals) > 0)
        <div class="section">
            <div class="section-title">Frequent Late Arrivals</div>
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th class="text-right">Late Count</th>
                        <th class="text-right">Avg Late (mins)</th>
                        <th class="text-right">Present %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($late_arrivals as $emp)
                    <tr>
                        <td>{{ $emp['name'] ?? '' }}</td>
                        <td>{{ $emp['department'] ?? '' }}</td>
                        <td class="text-right">{{ $emp['late_count'] ?? 0 }}</td>
                        <td class="text-right">{{ number_format($emp['avg_late_minutes'] ?? 0, 0) }}</td>
                        <td class="text-right">{{ number_format($emp['attendance_rate'] ?? 0, 1) }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <!-- Daily Trend -->
        @if(isset($daily_trend) && count($daily_trend) > 0)
        <div class="section">
            <div class="section-title">Daily Attendance Trend</div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th class="text-right">Present</th>
                        <th class="text-right">Late</th>
                        <th class="text-right">Absent</th>
                        <th class="text-right">Leave</th>
                        <th class="text-right">Rate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($daily_trend as $day)
                    <tr>
                        <td>{{ $day['date'] ?? '' }}</td>
                        <td>{{ $day['day_name'] ?? '' }}</td>
                        <td class="text-right" style="color: #166534;">{{ $day['present'] ?? 0 }}</td>
                        <td class="text-right" style="color: #92400e;">{{ $day['late'] ?? 0 }}</td>
                        <td class="text-right" style="color: #991b1b;">{{ $day['absent'] ?? 0 }}</td>
                        <td class="text-right" style="color: #1e40af;">{{ $day['leave'] ?? 0 }}</td>
                        <td class="text-right">
                            <div class="progress-bar" style="width: 60px; display: inline-block; vertical-align: middle;">
                                @php $rate = $day['rate'] ?? 0; @endphp
                                <div class="progress-fill {{ $rate >= 95 ? 'excellent' : ($rate >= 85 ? 'good' : 'poor') }}" style="width: {{ $rate }}%;"></div>
                            </div>
                            {{ number_format($rate, 0) }}%
                        </td>
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

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Payslip {{ $payslip->payslip_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #333; }
        .container { padding: 15px; }
        .header { display: table; width: 100%; margin-bottom: 20px; border-bottom: 2px solid #2980b9; padding-bottom: 15px; }
        .header-left, .header-right { display: table-cell; width: 50%; vertical-align: top; }
        .company-name { font-size: 18px; font-weight: bold; color: #2980b9; margin-bottom: 5px; }
        .company-details { color: #666; font-size: 10px; }
        .payslip-title { text-align: right; font-size: 20px; font-weight: bold; color: #2980b9; }
        .payslip-period { text-align: right; font-size: 12px; color: #666; }
        .employee-section { background: #f8f9fa; padding: 15px; margin-bottom: 15px; border-radius: 5px; }
        .employee-grid { display: table; width: 100%; }
        .employee-col { display: table-cell; width: 50%; vertical-align: top; }
        .label { color: #666; font-size: 10px; text-transform: uppercase; }
        .value { font-weight: bold; margin-bottom: 8px; }
        .earnings-deductions { display: table; width: 100%; margin-bottom: 15px; }
        .earnings, .deductions { display: table-cell; width: 50%; vertical-align: top; padding: 10px; }
        .earnings { padding-right: 10px; }
        .deductions { padding-left: 10px; }
        .section-title { font-weight: bold; font-size: 12px; color: #2980b9; border-bottom: 1px solid #2980b9; padding-bottom: 5px; margin-bottom: 10px; text-transform: uppercase; }
        .item-row { display: table; width: 100%; padding: 5px 0; border-bottom: 1px dotted #ddd; }
        .item-name { display: table-cell; width: 70%; }
        .item-amount { display: table-cell; width: 30%; text-align: right; }
        .total-row { font-weight: bold; background: #e8f4f8; padding: 8px 5px; margin-top: 10px; }
        .net-pay-section { background: #2980b9; color: #fff; padding: 15px; text-align: center; margin: 15px 0; border-radius: 5px; }
        .net-pay-label { font-size: 12px; text-transform: uppercase; }
        .net-pay-amount { font-size: 24px; font-weight: bold; }
        .summary-section { display: table; width: 100%; margin-bottom: 15px; }
        .summary-box { display: table-cell; width: 25%; padding: 10px; text-align: center; background: #f8f9fa; }
        .summary-box + .summary-box { border-left: 1px solid #ddd; }
        .summary-value { font-size: 14px; font-weight: bold; color: #2980b9; }
        .summary-label { font-size: 9px; color: #666; text-transform: uppercase; }
        .ytd-section { margin-top: 15px; }
        .ytd-table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .ytd-table th { background: #f0f0f0; padding: 5px; text-align: left; border: 1px solid #ddd; }
        .ytd-table td { padding: 5px; border: 1px solid #ddd; }
        .ytd-table td:last-child { text-align: right; }
        .footer { margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 9px; color: #666; text-align: center; }
        .confidential { color: #e74c3c; font-weight: bold; text-transform: uppercase; font-size: 10px; margin-bottom: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $organization->legal_name ?? $organization->name }}</div>
                <div class="company-details">
                    @if($organization->address_line_1){{ $organization->address_line_1 }}<br>@endif
                    @if($organization->city){{ $organization->city }}, {{ $organization->state ?? '' }} {{ $organization->postal_code ?? '' }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="payslip-title">PAYSLIP</div>
                <div class="payslip-period">
                    {{ $payslip->payrollPeriod->name }}<br>
                    <small>{{ $payslip->payslip_number }}</small>
                </div>
            </div>
        </div>

        <div class="confidential">Confidential - Employee Copy</div>

        <div class="employee-section">
            <div class="employee-grid">
                <div class="employee-col">
                    <div class="label">Employee Name</div>
                    <div class="value">{{ $employee->first_name }} {{ $employee->last_name }}</div>

                    <div class="label">Employee ID</div>
                    <div class="value">{{ $employee->employee_number }}</div>

                    <div class="label">Department</div>
                    <div class="value">{{ $employee->department?->name ?? 'N/A' }}</div>
                </div>
                <div class="employee-col">
                    <div class="label">Designation</div>
                    <div class="value">{{ $employee->designation?->title ?? 'N/A' }}</div>

                    <div class="label">Date of Joining</div>
                    <div class="value">{{ $employee->joining_date?->format('M d, Y') }}</div>

                    <div class="label">Payment Date</div>
                    <div class="value">{{ $payslip->payment_date?->format('M d, Y') ?? 'Pending' }}</div>
                </div>
            </div>
        </div>

        <div class="summary-section">
            <div class="summary-box">
                <div class="summary-value">{{ $payslip->total_working_days }}</div>
                <div class="summary-label">Working Days</div>
            </div>
            <div class="summary-box">
                <div class="summary-value">{{ $payslip->days_worked }}</div>
                <div class="summary-label">Days Worked</div>
            </div>
            <div class="summary-box">
                <div class="summary-value">{{ $payslip->days_on_leave }}</div>
                <div class="summary-label">Leave Days</div>
            </div>
            <div class="summary-box">
                <div class="summary-value">{{ number_format($payslip->overtime_hours, 1) }}</div>
                <div class="summary-label">OT Hours</div>
            </div>
        </div>

        <div class="earnings-deductions">
            <div class="earnings">
                <div class="section-title">Earnings</div>
                @foreach($earnings as $earning)
                <div class="item-row">
                    <div class="item-name">{{ $earning->name }}</div>
                    <div class="item-amount">{{ number_format($earning->amount, 2) }}</div>
                </div>
                @endforeach
                <div class="item-row total-row">
                    <div class="item-name">Gross Earnings</div>
                    <div class="item-amount">{{ $payslip->currency_code }} {{ number_format($payslip->gross_earnings, 2) }}</div>
                </div>
            </div>
            <div class="deductions">
                <div class="section-title">Deductions</div>
                @foreach($deductions as $deduction)
                <div class="item-row">
                    <div class="item-name">{{ $deduction->name }}</div>
                    <div class="item-amount">{{ number_format($deduction->amount, 2) }}</div>
                </div>
                @endforeach
                <div class="item-row total-row">
                    <div class="item-name">Total Deductions</div>
                    <div class="item-amount">{{ $payslip->currency_code }} {{ number_format($payslip->total_deductions, 2) }}</div>
                </div>
            </div>
        </div>

        <div class="net-pay-section">
            <div class="net-pay-label">Net Pay</div>
            <div class="net-pay-amount">{{ $payslip->currency_code }} {{ number_format($payslip->net_salary, 2) }}</div>
        </div>

        @if($payslip->payment_mode)
        <div style="text-align: center; margin-bottom: 15px; font-size: 10px; color: #666;">
            Payment Method: {{ ucfirst($payslip->payment_mode) }}
            @if($payslip->payment_reference)
            | Reference: {{ $payslip->payment_reference }}
            @endif
        </div>
        @endif

        @if(count($ytdData ?? []) > 0)
        <div class="ytd-section">
            <div class="section-title">Year to Date Summary</div>
            <table class="ytd-table">
                <thead>
                    <tr>
                        <th>Component</th>
                        <th>YTD Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ytdData as $item)
                    <tr>
                        <td>{{ $item['name'] }}</td>
                        <td>{{ number_format($item['amount'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="footer">
            This is a computer-generated payslip and does not require a signature.<br>
            For any queries, please contact HR Department.<br>
            <br>
            {{ $organization->legal_name ?? $organization->name }}
        </div>
    </div>
</body>
</html>

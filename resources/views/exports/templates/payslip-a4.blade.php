<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip - {{ $payslip->payslip_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 15mm; }

        .header { display: table; width: 100%; margin-bottom: 20px; }
        .header-left { display: table-cell; width: 60%; vertical-align: top; }
        .header-right { display: table-cell; width: 40%; vertical-align: top; text-align: right; }

        .company-name { font-size: 18px; font-weight: bold; color: #1e40af; }
        .company-details { font-size: 9px; color: #666; margin-top: 5px; line-height: 1.5; }

        .payslip-title { font-size: 20px; font-weight: bold; color: #1e40af; }
        .payslip-number { font-size: 12px; color: #666; margin-top: 5px; }
        .period-badge {
            display: inline-block;
            padding: 5px 15px;
            background: #dbeafe;
            color: #1e40af;
            font-size: 10px;
            font-weight: bold;
            border-radius: 4px;
            margin-top: 10px;
        }

        .divider { border-top: 2px solid #1e40af; margin: 15px 0; }
        .divider-light { border-top: 1px solid #e5e7eb; margin: 10px 0; }

        .info-section { display: table; width: 100%; margin-bottom: 20px; }
        .info-box { display: table-cell; width: 50%; vertical-align: top; padding: 10px; background: #f9fafb; }
        .info-box:first-child { border-right: 1px solid #e5e7eb; }
        .info-title {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 5px;
        }
        .info-row { display: table; width: 100%; margin: 4px 0; }
        .info-label { display: table-cell; width: 40%; color: #666; font-size: 10px; }
        .info-value { display: table-cell; width: 60%; font-weight: 500; font-size: 10px; }

        .attendance-summary {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
        }
        .attendance-item {
            display: table-cell;
            text-align: center;
            padding: 10px;
            border-right: 1px solid #bfdbfe;
        }
        .attendance-item:last-child { border-right: none; }
        .attendance-value { font-size: 16px; font-weight: bold; color: #1e40af; }
        .attendance-label { font-size: 8px; color: #6b7280; text-transform: uppercase; margin-top: 3px; }

        .earnings-deductions { display: table; width: 100%; margin-bottom: 20px; }
        .earnings-col, .deductions-col { display: table-cell; width: 50%; vertical-align: top; }
        .earnings-col { padding-right: 10px; }
        .deductions-col { padding-left: 10px; }

        .section-title {
            font-weight: bold;
            font-size: 11px;
            padding: 8px 10px;
            color: #fff;
            margin-bottom: 1px;
        }
        .section-title.earnings { background: #059669; }
        .section-title.deductions { background: #dc2626; }

        .items-table { width: 100%; border-collapse: collapse; }
        .items-table td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
        .items-table tr:nth-child(even) { background: #f9fafb; }
        .items-table .amount { text-align: right; font-family: monospace; }

        .subtotal-row { background: #f3f4f6 !important; font-weight: bold; }
        .subtotal-row td { border-top: 2px solid #d1d5db; }

        .net-salary-box {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: #fff;
            padding: 15px;
            text-align: center;
            border-radius: 8px;
            margin: 20px 0;
        }
        .net-salary-label { font-size: 12px; text-transform: uppercase; opacity: 0.9; }
        .net-salary-value { font-size: 28px; font-weight: bold; margin-top: 5px; }
        .net-salary-words { font-size: 10px; opacity: 0.8; margin-top: 5px; font-style: italic; }

        .ytd-section { margin-top: 20px; }
        .ytd-title { font-weight: bold; font-size: 10px; color: #6b7280; margin-bottom: 10px; text-transform: uppercase; }
        .ytd-table { width: 100%; border-collapse: collapse; }
        .ytd-table th { background: #f3f4f6; padding: 6px 10px; text-align: left; font-size: 9px; font-weight: bold; }
        .ytd-table td { padding: 5px 10px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
        .ytd-table td.amount { text-align: right; font-family: monospace; }

        .footer-section { display: table; width: 100%; margin-top: 30px; }
        .footer-box { display: table-cell; width: 50%; }
        .signature-area {
            border-top: 1px solid #333;
            width: 150px;
            margin-top: 40px;
            padding-top: 5px;
            font-size: 9px;
            color: #666;
        }

        .confidential {
            text-align: center;
            font-size: 8px;
            color: #999;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
        }

        .statutory-note {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 4px;
            padding: 10px;
            font-size: 9px;
            color: #92400e;
            margin: 15px 0;
        }

        @page { margin: 10mm; }
        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                @if(isset($organization) && $organization->logo_url)
                <img src="{{ $organization->logo_url }}" alt="{{ $organization->name }}" style="max-height: 50px; margin-bottom: 10px;">
                @endif
                <div class="company-name">{{ $organization->name ?? 'Company Name' }}</div>
                <div class="company-details">
                    @if($organization->address_line_1 ?? null){{ $organization->address_line_1 }}<br>@endif
                    {{ $organization->city ?? '' }}@if(($organization->city ?? null) && ($organization->country ?? null)), @endif{{ $organization->country ?? '' }}<br>
                    @if($organization->tax_number ?? null)Tax/VAT No: {{ $organization->tax_number }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="payslip-title">PAYSLIP</div>
                <div class="payslip-number"># {{ $payslip->payslip_number }}</div>
                <div class="period-badge">{{ $payslip->payrollPeriod->name ?? 'Pay Period' }}</div>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Employee & Payment Info -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-title">Employee Details</div>
                <div class="info-row">
                    <div class="info-label">Name:</div>
                    <div class="info-value">{{ $payslip->employee->getDisplayName() }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Employee ID:</div>
                    <div class="info-value">{{ $payslip->employee->employee_number }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Department:</div>
                    <div class="info-value">{{ $payslip->employee->department->name ?? 'N/A' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Designation:</div>
                    <div class="info-value">{{ $payslip->employee->designation->name ?? 'N/A' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Join Date:</div>
                    <div class="info-value">{{ $payslip->employee->date_of_joining?->format('d M Y') ?? 'N/A' }}</div>
                </div>
            </div>
            <div class="info-box">
                <div class="info-title">Payment Details</div>
                <div class="info-row">
                    <div class="info-label">Pay Period:</div>
                    <div class="info-value">
                        {{ $payslip->payrollPeriod->start_date?->format('d M') }} - {{ $payslip->payrollPeriod->end_date?->format('d M Y') }}
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Payment Date:</div>
                    <div class="info-value">{{ $payslip->payment_date?->format('d M Y') ?? 'Pending' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Payment Mode:</div>
                    <div class="info-value">{{ ucfirst($payslip->payment_mode ?? 'Bank Transfer') }}</div>
                </div>
                @if($payslip->employee->bank_account_number ?? null)
                <div class="info-row">
                    <div class="info-label">Bank Account:</div>
                    <div class="info-value">****{{ substr($payslip->employee->bank_account_number, -4) }}</div>
                </div>
                @endif
            </div>
        </div>

        <!-- Attendance Summary -->
        <div class="attendance-summary">
            <div class="attendance-item">
                <div class="attendance-value">{{ $payslip->total_working_days ?? 0 }}</div>
                <div class="attendance-label">Working Days</div>
            </div>
            <div class="attendance-item">
                <div class="attendance-value">{{ $payslip->days_worked ?? 0 }}</div>
                <div class="attendance-label">Days Worked</div>
            </div>
            <div class="attendance-item">
                <div class="attendance-value">{{ $payslip->days_on_leave ?? 0 }}</div>
                <div class="attendance-label">Leave Days</div>
            </div>
            <div class="attendance-item">
                <div class="attendance-value">{{ $payslip->unpaid_leave_days ?? 0 }}</div>
                <div class="attendance-label">Unpaid Leave</div>
            </div>
            <div class="attendance-item">
                <div class="attendance-value">{{ number_format($payslip->overtime_hours ?? 0, 1) }}</div>
                <div class="attendance-label">OT Hours</div>
            </div>
        </div>

        <!-- Earnings & Deductions -->
        <div class="earnings-deductions">
            <div class="earnings-col">
                <div class="section-title earnings">Earnings</div>
                <table class="items-table">
                    @php
                        $earnings = $payslip->items->where('type', 'earning');
                    @endphp
                    @foreach($earnings as $item)
                    <tr>
                        <td>{{ $item->name }}</td>
                        <td class="amount">{{ number_format((float)$item->amount, 2) }}</td>
                    </tr>
                    @endforeach
                    <tr class="subtotal-row">
                        <td>Gross Earnings</td>
                        <td class="amount">{{ $payslip->currency_code }} {{ number_format((float)$payslip->gross_earnings, 2) }}</td>
                    </tr>
                </table>
            </div>
            <div class="deductions-col">
                <div class="section-title deductions">Deductions</div>
                <table class="items-table">
                    @php
                        $deductions = $payslip->items->where('type', 'deduction');
                    @endphp
                    @foreach($deductions as $item)
                    <tr>
                        <td>{{ $item->name }}</td>
                        <td class="amount">{{ number_format((float)$item->amount, 2) }}</td>
                    </tr>
                    @endforeach
                    <tr class="subtotal-row">
                        <td>Total Deductions</td>
                        <td class="amount">{{ $payslip->currency_code }} {{ number_format((float)$payslip->total_deductions, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Net Salary -->
        <div class="net-salary-box">
            <div class="net-salary-label">Net Salary Payable</div>
            <div class="net-salary-value">{{ $payslip->currency_code }} {{ number_format((float)$payslip->net_salary, 2) }}</div>
            @if(isset($amountInWords))
            <div class="net-salary-words">{{ $amountInWords }}</div>
            @endif
        </div>

        <!-- Statutory Note (for countries with statutory contributions) -->
        @if($payslip->items->where('salary_component.is_statutory', true)->count() > 0)
        <div class="statutory-note">
            <strong>Note:</strong> Statutory contributions shown above are as per applicable regulations.
            @if($organization->country_code ?? null === 'IN')
            EPF, ESI, and Professional Tax deductions are made as per Indian statutory requirements.
            @elseif(in_array($organization->country_code ?? '', ['SA', 'AE', 'OM', 'BH', 'KW', 'QA']))
            Social insurance contributions are made as per GCC regulations.
            @endif
        </div>
        @endif

        <!-- YTD Summary -->
        @if($earnings->count() > 0)
        <div class="ytd-section">
            <div class="ytd-title">Year-to-Date Summary</div>
            <table class="ytd-table">
                <thead>
                    <tr>
                        <th>Component</th>
                        <th class="amount">Current Month</th>
                        <th class="amount">YTD</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Gross Earnings</td>
                        <td class="amount">{{ number_format((float)$payslip->gross_earnings, 2) }}</td>
                        <td class="amount">{{ number_format((float)$earnings->sum('ytd_amount') + $payslip->gross_earnings, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Total Deductions</td>
                        <td class="amount">{{ number_format((float)$payslip->total_deductions, 2) }}</td>
                        <td class="amount">{{ number_format((float)$deductions->sum('ytd_amount') + $payslip->total_deductions, 2) }}</td>
                    </tr>
                    <tr>
                        <td><strong>Net Salary</strong></td>
                        <td class="amount"><strong>{{ number_format((float)$payslip->net_salary, 2) }}</strong></td>
                        <td class="amount"><strong>{{ number_format((float)($earnings->sum('ytd_amount') - $deductions->sum('ytd_amount') + $payslip->net_salary), 2) }}</strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
        @endif

        <!-- Footer -->
        <div class="footer-section">
            <div class="footer-box">
                <div class="signature-area">Employee Signature</div>
            </div>
            <div class="footer-box" style="text-align: right;">
                <div class="signature-area" style="margin-left: auto;">Authorized Signature</div>
            </div>
        </div>

        <div class="confidential">
            This is a computer-generated payslip. No signature required.<br>
            Confidential: For employee's personal use only.<br>
            Generated: {{ now()->format('d M Y H:i') }}
        </div>
    </div>
</body>
</html>

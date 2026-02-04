<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Receipt {{ $payment->payment_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 15mm; max-width: 210mm; margin: 0 auto; }

        .header { display: table; width: 100%; margin-bottom: 25px; }
        .header-left, .header-right { display: table-cell; width: 50%; vertical-align: top; }
        .header-right { text-align: right; }

        .logo { max-height: 60px; margin-bottom: 10px; }
        .company-name { font-size: 18px; font-weight: bold; color: {{ $primaryColor }}; }
        .company-details { color: #666; font-size: 10px; line-height: 1.5; }

        .receipt-title { font-size: 28px; font-weight: bold; color: {{ $primaryColor }}; }
        .receipt-number { font-size: 14px; color: #666; margin-top: 5px; }

        .divider { border-top: 2px solid {{ $secondaryColor }}; margin: 20px 0; }

        .receipt-box {
            border: 2px solid {{ $primaryColor }};
            border-radius: 8px;
            padding: 25px;
            margin: 20px 0;
            background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%);
        }

        .amount-section { text-align: center; margin: 20px 0; padding: 20px; background: {{ $primaryColor }}; color: #fff; border-radius: 5px; }
        .amount-label { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; }
        .amount-value { font-size: 36px; font-weight: bold; margin-top: 5px; }
        .amount-words { font-size: 11px; font-style: italic; margin-top: 5px; opacity: 0.9; }

        .details-grid { display: table; width: 100%; margin: 20px 0; }
        .details-row { display: table-row; }
        .details-label { display: table-cell; width: 35%; padding: 8px 10px; color: #666; font-size: 11px; border-bottom: 1px solid #eee; }
        .details-value { display: table-cell; width: 65%; padding: 8px 10px; font-weight: 500; border-bottom: 1px solid #eee; }

        .from-to-section { display: table; width: 100%; margin: 25px 0; }
        .from-box, .to-box { display: table-cell; width: 50%; vertical-align: top; padding: 15px; }
        .section-title { font-weight: bold; font-size: 10px; text-transform: uppercase; color: #666; margin-bottom: 8px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        .section-content { font-size: 11px; }

        .allocations-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .allocations-table th {
            background: {{ $primaryColor }};
            color: #fff;
            padding: 10px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }
        .allocations-table td { padding: 10px; border-bottom: 1px solid #eee; font-size: 11px; }
        .allocations-table tr:nth-child(even) { background: #f9f9f9; }
        .text-right { text-align: right; }

        .signature-section { display: table; width: 100%; margin-top: 50px; }
        .signature-box { display: table-cell; width: 50%; text-align: center; }
        .signature-line { border-top: 1px solid #333; width: 150px; margin: 0 auto; padding-top: 5px; }
        .signature-label { font-size: 10px; color: #666; }

        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 9px; border-top: 1px solid #ddd; padding-top: 15px; }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px;
            color: rgba(39, 174, 96, 0.1);
            font-weight: bold;
            z-index: -1;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
            background: #27ae60;
            color: #fff;
            margin-top: 10px;
        }

        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="watermark">PAID</div>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                @if($showLogo && $organization->logo_url)
                <img src="{{ $organization->logo_url }}" alt="{{ $organization->name }}" class="logo">
                @endif
                <div class="company-name">{{ $organization->legal_name ?? $organization->name }}</div>
                <div class="company-details">
                    @if($organization->address_line_1){{ $organization->address_line_1 }}<br>@endif
                    {{ $organization->city ?? '' }}@if($organization->city && $organization->postal_code), @endif{{ $organization->postal_code ?? '' }}<br>
                    @if($organization->tax_number)Tax No: {{ $organization->tax_number }}<br>@endif
                    @if($organization->phone)Tel: {{ $organization->phone }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="receipt-title">PAYMENT RECEIPT</div>
                <div class="receipt-number"># {{ $payment->payment_number }}</div>
                <span class="status-badge">RECEIVED</span>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Receipt Box -->
        <div class="receipt-box">
            <!-- Amount Section -->
            <div class="amount-section">
                <div class="amount-label">Amount Received</div>
                <div class="amount-value">{{ $payment->currency_code }} {{ number_format((float)$payment->amount, 2) }}</div>
                @if($payment->amount_in_words ?? null)
                <div class="amount-words">{{ $payment->amount_in_words }}</div>
                @endif
            </div>

            <!-- From/To Section -->
            <div class="from-to-section">
                <div class="from-box">
                    <div class="section-title">Received From</div>
                    <div class="section-content">
                        <strong>{{ $customer->company_name ?? $payment->customer_name ?? 'N/A' }}</strong><br>
                        @if($customer->billing_address ?? null){!! nl2br(e($customer->billing_address)) !!}<br>@endif
                        @if($customer->tax_number ?? null)Tax No: {{ $customer->tax_number }}<br>@endif
                        @if($customer->email ?? null){{ $customer->email }}@endif
                    </div>
                </div>
                <div class="to-box">
                    <div class="section-title">Payment Details</div>
                    <div class="section-content">
                        <table style="width: 100%;">
                            <tr>
                                <td style="color: #666; padding: 3px 0;">Receipt Date:</td>
                                <td style="text-align: right; font-weight: bold;">{{ $payment->payment_date->format('d M Y') }}</td>
                            </tr>
                            <tr>
                                <td style="color: #666; padding: 3px 0;">Payment Method:</td>
                                <td style="text-align: right; font-weight: bold;">{{ ucfirst($payment->payment_method ?? 'Cash') }}</td>
                            </tr>
                            @if($payment->reference)
                            <tr>
                                <td style="color: #666; padding: 3px 0;">Reference:</td>
                                <td style="text-align: right; font-weight: bold;">{{ $payment->reference }}</td>
                            </tr>
                            @endif
                            @if($payment->bank_account)
                            <tr>
                                <td style="color: #666; padding: 3px 0;">Deposited To:</td>
                                <td style="text-align: right; font-weight: bold;">{{ $payment->bank_account->name ?? 'Bank Account' }}</td>
                            </tr>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Applied To Invoices -->
        @if($allocations && $allocations->count() > 0)
        <h3 style="font-size: 12px; color: #666; margin-bottom: 10px; text-transform: uppercase;">Applied to Invoices</h3>
        <table class="allocations-table">
            <thead>
                <tr>
                    <th>Invoice Number</th>
                    <th>Invoice Date</th>
                    <th class="text-right">Invoice Amount</th>
                    <th class="text-right">Amount Applied</th>
                    <th class="text-right">Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($allocations as $allocation)
                <tr>
                    <td>{{ $allocation->invoice->invoice_number ?? 'N/A' }}</td>
                    <td>{{ $allocation->invoice->invoice_date?->format('d M Y') ?? '-' }}</td>
                    <td class="text-right">{{ number_format((float)($allocation->invoice->total ?? 0), 2) }}</td>
                    <td class="text-right">{{ number_format((float)$allocation->amount, 2) }}</td>
                    <td class="text-right">{{ number_format((float)($allocation->invoice->amount_due ?? 0), 2) }}</td>
                </tr>
                @endforeach
                <tr style="font-weight: bold; background: #f0f0f0;">
                    <td colspan="3">Total Applied</td>
                    <td class="text-right">{{ $payment->currency_code }} {{ number_format($allocations->sum('amount'), 2) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        @endif

        <!-- Notes -->
        @if($payment->notes)
        <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
            <strong style="font-size: 10px; color: #666;">Notes:</strong><br>
            <span style="font-size: 11px;">{!! nl2br(e($payment->notes)) !!}</span>
        </div>
        @endif

        <!-- Signatures -->
        @if($showSignature)
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">Received By</div>
                <div class="signature-label">Cashier Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Paid By</div>
                <div class="signature-label">Customer Signature</div>
            </div>
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            This is a computer-generated receipt.<br>
            {{ $organization->legal_name ?? $organization->name }}
            @if($organization->website) | {{ $organization->website }} @endif
            <br>Generated: {{ now()->format('d M Y H:i') }}
        </div>
    </div>
</body>
</html>

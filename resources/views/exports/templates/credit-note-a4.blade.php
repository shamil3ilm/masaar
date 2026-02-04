<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Credit Note {{ $creditNote->credit_note_number ?? $creditNote->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 15mm; }

        .header { display: table; width: 100%; margin-bottom: 25px; }
        .header-left, .header-right { display: table-cell; width: 50%; vertical-align: top; }
        .header-right { text-align: right; }

        .logo { max-height: 60px; margin-bottom: 10px; }
        .company-name { font-size: 18px; font-weight: bold; color: {{ $primaryColor }}; }
        .company-details { color: #666; font-size: 10px; line-height: 1.5; }

        .doc-title { font-size: 28px; font-weight: bold; color: #c0392b; }
        .doc-number { font-size: 14px; color: #666; margin-top: 5px; }

        .credit-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
            background: #c0392b;
            color: #fff;
            margin-top: 10px;
        }

        .divider { border-top: 2px solid #c0392b; margin: 20px 0; }

        .reference-box {
            background: #fef5f5;
            border: 1px solid #f5c6cb;
            border-left: 4px solid #c0392b;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .reference-title { font-weight: bold; font-size: 11px; color: #c0392b; margin-bottom: 5px; }
        .reference-content { font-size: 11px; }

        .info-section { display: table; width: 100%; margin-bottom: 25px; }
        .info-box { display: table-cell; width: 50%; vertical-align: top; padding-right: 20px; }
        .info-box:last-child { padding-right: 0; padding-left: 20px; }
        .info-box-title {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 8px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        .info-content { padding: 5px 0; font-size: 11px; }

        .details-table { margin-left: auto; }
        .details-table td { padding: 4px 10px; font-size: 11px; }
        .details-table td:first-child { color: #666; text-align: right; }
        .details-table td:last-child { font-weight: bold; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .items-table th {
            background: #c0392b;
            color: #fff;
            padding: 10px 8px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }
        .items-table th.text-right, .items-table td.text-right { text-align: right; }
        .items-table th.text-center, .items-table td.text-center { text-align: center; }
        .items-table td { padding: 10px 8px; border-bottom: 1px solid #eee; font-size: 11px; }
        .items-table tr:nth-child(even) { background: #fef5f5; }

        .totals-section { display: table; width: 100%; }
        .notes-section { display: table-cell; width: 55%; vertical-align: top; padding-right: 30px; }
        .totals-container { display: table-cell; width: 45%; vertical-align: top; }

        .totals-table { width: 100%; }
        .totals-table td { padding: 6px 10px; font-size: 11px; }
        .totals-table td:first-child { text-align: right; color: #666; }
        .totals-table td:last-child { text-align: right; font-weight: bold; width: 120px; }

        .total-row { background: #c0392b; color: #fff; }
        .total-row td { padding: 12px 10px !important; font-size: 14px; }

        .notes-title { font-weight: bold; font-size: 10px; text-transform: uppercase; color: #666; margin-bottom: 5px; }
        .notes-content { font-size: 10px; color: #666; padding: 10px; background: #f9f9f9; border-radius: 4px; }

        .reason-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 12px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .reason-title { font-weight: bold; font-size: 11px; color: #856404; margin-bottom: 5px; }
        .reason-content { font-size: 11px; color: #856404; }

        .qr-section { text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd; }
        .qr-code img { max-width: 100px; }
        .qr-label { font-size: 8px; color: #666; margin-top: 5px; }

        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 9px; border-top: 1px solid #ddd; padding-top: 15px; }

        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
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
                    {{ $organization->city ?? '' }}@if($organization->city && $organization->state), @endif{{ $organization->state ?? '' }} {{ $organization->postal_code ?? '' }}<br>
                    @if($organization->tax_number)Tax No: {{ $organization->tax_number }}<br>@endif
                    @if($organization->phone)Tel: {{ $organization->phone }}<br>@endif
                    @if($organization->email){{ $organization->email }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="doc-title">CREDIT NOTE</div>
                <div class="doc-number"># {{ $creditNote->credit_note_number ?? $creditNote->invoice_number }}</div>
                <span class="credit-badge">CREDIT</span>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Original Invoice Reference -->
        @if($originalInvoice ?? null)
        <div class="reference-box">
            <div class="reference-title">Reference to Original Invoice</div>
            <div class="reference-content">
                <strong>Invoice Number:</strong> {{ $originalInvoice->invoice_number }}<br>
                <strong>Invoice Date:</strong> {{ $originalInvoice->invoice_date->format('d M Y') }}<br>
                <strong>Original Amount:</strong> {{ $originalInvoice->currency_code }} {{ number_format((float)$originalInvoice->total, 2) }}
            </div>
        </div>
        @endif

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Credit To</div>
                <div class="info-content">
                    <strong>{{ $creditNote->customer_name ?? $customer->company_name ?? 'N/A' }}</strong><br>
                    @if($creditNote->billing_address ?? $customer->billing_address ?? null)
                        {!! nl2br(e($creditNote->billing_address ?? $customer->billing_address)) !!}<br>
                    @endif
                    @if($creditNote->customer_tax_number ?? $customer->tax_number ?? null)
                        Tax No: {{ $creditNote->customer_tax_number ?? $customer->tax_number }}<br>
                    @endif
                    @if($creditNote->customer_email ?? $customer->email ?? null)
                        {{ $creditNote->customer_email ?? $customer->email }}
                    @endif
                </div>
            </div>
            <div class="info-box" style="text-align: right;">
                <table class="details-table">
                    <tr>
                        <td>Credit Note Date:</td>
                        <td>{{ ($creditNote->credit_note_date ?? $creditNote->invoice_date)->format('d M Y') }}</td>
                    </tr>
                    @if($creditNote->reference)
                    <tr>
                        <td>Reference:</td>
                        <td>{{ $creditNote->reference }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>Currency:</td>
                        <td>{{ $creditNote->currency_code }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Reason for Credit Note -->
        @if($creditNote->reason ?? $creditNote->notes ?? null)
        <div class="reason-box">
            <div class="reason-title">Reason for Credit Note</div>
            <div class="reason-content">{{ $creditNote->reason ?? $creditNote->notes }}</div>
        </div>
        @endif

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%">#</th>
                    <th style="width: 45%">Description</th>
                    <th class="text-center" style="width: 12%">Qty</th>
                    <th class="text-right" style="width: 15%">Unit Price</th>
                    <th class="text-right" style="width: 10%">Tax</th>
                    <th class="text-right" style="width: 15%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line->description }}</td>
                    <td class="text-center">{{ number_format((float)$line->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format((float)$line->unit_price, 2) }}</td>
                    <td class="text-right">{{ $line->tax_rate ?? 0 }}%</td>
                    <td class="text-right">{{ number_format((float)$line->total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-section">
            <div class="notes-section">
                @if($creditNote->notes && !($creditNote->reason ?? null))
                <div class="notes-title">Notes</div>
                <div class="notes-content">{!! nl2br(e($creditNote->notes)) !!}</div>
                @endif
            </div>
            <div class="totals-container">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal:</td>
                        <td>{{ number_format((float)$creditNote->subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Tax:</td>
                        <td>{{ number_format((float)$creditNote->tax_amount, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Credit Total:</td>
                        <td>{{ $creditNote->currency_code }} {{ number_format((float)$creditNote->total, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- QR Code -->
        @if($showQrCode && $creditNote->compliance_qr_code)
        <div class="qr-section">
            <img src="data:image/png;base64,{{ $creditNote->compliance_qr_code }}" alt="QR Code" class="qr-code">
            @if($creditNote->compliance_uuid)
            <div class="qr-label">UUID: {{ $creditNote->compliance_uuid }}</div>
            @endif
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            This credit note will be applied to your account.<br>
            {{ $organization->legal_name ?? $organization->name }}
            @if($organization->website) | {{ $organization->website }} @endif
            <br>Generated: {{ now()->format('d M Y H:i') }}
        </div>
    </div>
</body>
</html>

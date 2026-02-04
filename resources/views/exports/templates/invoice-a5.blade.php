<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @page { size: A5 portrait; margin: 8mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 9px;
            line-height: 1.3;
            color: #333;
        }

        .container { padding: 5mm; }

        /* Header - Compact */
        .header { display: table; width: 100%; margin-bottom: 10px; }
        .header-left, .header-right { display: table-cell; width: 50%; vertical-align: top; }
        .header-right { text-align: right; }

        .logo { max-height: 35px; max-width: 100px; margin-bottom: 5px; }
        .company-name { font-size: 12px; font-weight: bold; color: {{ $primaryColor }}; }
        .company-details { color: #666; font-size: 8px; line-height: 1.4; }

        .invoice-title { font-size: 16px; font-weight: bold; color: {{ $primaryColor }}; }
        .invoice-number { font-size: 10px; color: #666; }

        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 7px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .status-paid { background: #27ae60; color: #fff; }
        .status-sent { background: #3498db; color: #fff; }
        .status-overdue { background: #e74c3c; color: #fff; }
        .status-draft { background: #95a5a6; color: #fff; }

        .divider { border-top: 1px solid {{ $secondaryColor }}; margin: 8px 0; }

        /* Info Section - Compact */
        .info-section { display: table; width: 100%; margin-bottom: 10px; }
        .info-box { display: table-cell; width: 50%; vertical-align: top; font-size: 8px; }
        .info-box-title {
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 3px;
        }

        /* Items Table - Compact */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .items-table th {
            background: {{ $primaryColor }};
            color: #fff;
            padding: 4px;
            text-align: left;
            font-size: 7px;
            text-transform: uppercase;
        }
        .items-table th.text-right, .items-table td.text-right { text-align: right; }
        .items-table td { padding: 4px; border-bottom: 1px solid #eee; font-size: 8px; }

        /* Totals - Compact */
        .totals-section { display: table; width: 100%; }
        .totals-left { display: table-cell; width: 50%; vertical-align: top; }
        .totals-right { display: table-cell; width: 50%; vertical-align: top; }

        .totals-table { width: 100%; }
        .totals-table td { padding: 3px 5px; font-size: 8px; }
        .totals-table td:first-child { text-align: right; color: #666; }
        .totals-table td:last-child { text-align: right; font-weight: bold; }

        .total-row { background: {{ $primaryColor }}; color: #fff; }
        .total-row td { padding: 5px !important; font-size: 10px; }
        .balance-due { background: #e74c3c; }

        /* QR Code - Smaller */
        .qr-section { text-align: center; margin-top: 10px; }
        .qr-section img { max-width: 60px; }
        .qr-label { font-size: 6px; color: #666; }

        /* Footer */
        .footer { text-align: center; font-size: 7px; color: #666; margin-top: 10px; border-top: 1px solid #ddd; padding-top: 5px; }

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
                <div class="company-name">{{ $organization->name }}</div>
                <div class="company-details">
                    @if($organization->address_line_1){{ $organization->address_line_1 }}<br>@endif
                    {{ $organization->city ?? '' }} {{ $organization->postal_code ?? '' }}<br>
                    @if($organization->tax_number)Tax: {{ $organization->tax_number }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number"># {{ $invoice->invoice_number }}</div>
                <span class="status-badge status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Info -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Bill To</div>
                <strong>{{ $invoice->customer_name }}</strong><br>
                @if($invoice->billing_address){!! nl2br(e(Str::limit($invoice->billing_address, 60))) !!}<br>@endif
                @if($invoice->customer_tax_number)Tax: {{ $invoice->customer_tax_number }}@endif
            </div>
            <div class="info-box" style="text-align: right;">
                <div>Date: <strong>{{ $invoice->invoice_date->format('d/m/Y') }}</strong></div>
                @if($invoice->due_date)<div>Due: <strong>{{ $invoice->due_date->format('d/m/Y') }}</strong></div>@endif
                <div>Currency: <strong>{{ $invoice->currency_code }}</strong></div>
            </div>
        </div>

        <!-- Items -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 45%">Description</th>
                    <th class="text-right" style="width: 15%">Qty</th>
                    <th class="text-right" style="width: 20%">Price</th>
                    <th class="text-right" style="width: 20%">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $line)
                <tr>
                    <td>{{ Str::limit($line->description, 40) }}</td>
                    <td class="text-right">{{ number_format((float)$line->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format((float)$line->unit_price, 2) }}</td>
                    <td class="text-right">{{ number_format((float)$line->total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-section">
            <div class="totals-left">
                @if($showQrCode && $invoice->compliance_qr_code)
                <div class="qr-section" style="text-align: left;">
                    <img src="data:image/png;base64,{{ $invoice->compliance_qr_code }}" alt="QR">
                </div>
                @endif
            </div>
            <div class="totals-right">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal:</td>
                        <td>{{ number_format((float)$invoice->subtotal, 2) }}</td>
                    </tr>
                    @if((float)$invoice->discount_amount > 0)
                    <tr>
                        <td>Discount:</td>
                        <td>-{{ number_format((float)$invoice->discount_amount, 2) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>Tax:</td>
                        <td>{{ number_format((float)$invoice->tax_amount, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Total:</td>
                        <td>{{ $invoice->currency_code }} {{ number_format((float)$invoice->total, 2) }}</td>
                    </tr>
                    @if((float)$invoice->amount_due > 0 && $invoice->status !== 'paid')
                    <tr class="total-row balance-due">
                        <td>Due:</td>
                        <td>{{ $invoice->currency_code }} {{ number_format((float)$invoice->amount_due, 2) }}</td>
                    </tr>
                    @endif
                </table>
            </div>
        </div>

        <div class="footer">
            Thank you! | {{ $organization->name }} @if($organization->phone)| {{ $organization->phone }}@endif
        </div>
    </div>
</body>
</html>

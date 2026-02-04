<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }

        .container {
            padding: 20px;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }

        .header-left, .header-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .company-details {
            color: #666;
            font-size: 11px;
        }

        .invoice-title {
            text-align: right;
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
        }

        .invoice-number {
            text-align: right;
            font-size: 14px;
            color: #666;
        }

        .divider {
            border-top: 2px solid #3498db;
            margin: 20px 0;
        }

        .info-section {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .info-box {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 20px;
        }

        .info-box-title {
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 5px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 3px;
        }

        .info-content {
            padding: 5px 0;
        }

        .invoice-details {
            text-align: right;
        }

        .invoice-details table {
            margin-left: auto;
        }

        .invoice-details td {
            padding: 3px 10px;
        }

        .invoice-details td:first-child {
            color: #666;
            text-align: right;
        }

        .invoice-details td:last-child {
            font-weight: bold;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table th {
            background: #2c3e50;
            color: #fff;
            padding: 10px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
        }

        .items-table th:last-child,
        .items-table td:last-child {
            text-align: right;
        }

        .items-table td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }

        .items-table tr:nth-child(even) {
            background: #f9f9f9;
        }

        .totals-section {
            display: table;
            width: 100%;
        }

        .notes-section {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .totals-table-container {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .totals-table {
            margin-left: auto;
            width: 250px;
        }

        .totals-table td {
            padding: 5px 10px;
        }

        .totals-table td:first-child {
            text-align: right;
            color: #666;
        }

        .totals-table td:last-child {
            text-align: right;
            font-weight: bold;
        }

        .total-row {
            background: #2c3e50;
            color: #fff;
        }

        .total-row td {
            padding: 10px !important;
            font-size: 14px;
        }

        .balance-due {
            background: #e74c3c;
        }

        .notes-title {
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 5px;
        }

        .notes-content {
            font-size: 10px;
            color: #666;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 3px;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            color: #666;
            font-size: 10px;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }

        .qr-code {
            text-align: center;
            margin-top: 20px;
        }

        .qr-code img {
            max-width: 100px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
        }

        .status-paid { background: #27ae60; color: #fff; }
        .status-sent { background: #3498db; color: #fff; }
        .status-overdue { background: #e74c3c; color: #fff; }
        .status-draft { background: #95a5a6; color: #fff; }
        .status-partial { background: #f39c12; color: #fff; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $organization->legal_name ?? $organization->name }}</div>
                <div class="company-details">
                    @if($organization->address_line_1){{ $organization->address_line_1 }}<br>@endif
                    @if($organization->city){{ $organization->city }}, @endif
                    @if($organization->state){{ $organization->state }} @endif
                    @if($organization->postal_code){{ $organization->postal_code }}@endif
                    <br>
                    @if($organization->tax_number)Tax No: {{ $organization->tax_number }}<br>@endif
                    @if($organization->phone)Phone: {{ $organization->phone }}<br>@endif
                    @if($organization->email)Email: {{ $organization->email }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number"># {{ $invoice->invoice_number }}</div>
                <div style="text-align: right; margin-top: 10px;">
                    <span class="status-badge status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
                </div>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Bill To & Invoice Details -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Bill To</div>
                <div class="info-content">
                    <strong>{{ $invoice->customer_name }}</strong><br>
                    @if($invoice->billing_address)
                        {!! nl2br(e($invoice->billing_address)) !!}<br>
                    @endif
                    @if($invoice->customer_tax_number)
                        Tax No: {{ $invoice->customer_tax_number }}<br>
                    @endif
                    @if($invoice->customer_email)
                        {{ $invoice->customer_email }}
                    @endif
                </div>
            </div>
            <div class="info-box invoice-details">
                <table>
                    <tr>
                        <td>Invoice Date:</td>
                        <td>{{ $invoice->invoice_date->format('M d, Y') }}</td>
                    </tr>
                    @if($invoice->due_date)
                    <tr>
                        <td>Due Date:</td>
                        <td>{{ $invoice->due_date->format('M d, Y') }}</td>
                    </tr>
                    @endif
                    @if($invoice->reference)
                    <tr>
                        <td>Reference:</td>
                        <td>{{ $invoice->reference }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>Currency:</td>
                        <td>{{ $invoice->currency_code }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 40%">Description</th>
                    <th style="width: 10%">Qty</th>
                    <th style="width: 15%">Unit Price</th>
                    <th style="width: 10%">Tax</th>
                    <th style="width: 15%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ number_format($line->quantity, 2) }}</td>
                    <td>{{ number_format($line->unit_price, 2) }}</td>
                    <td>{{ $line->tax_rate }}%</td>
                    <td>{{ number_format($line->total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals & Notes -->
        <div class="totals-section">
            <div class="notes-section">
                @if($invoice->notes)
                <div class="notes-title">Notes</div>
                <div class="notes-content">
                    {!! nl2br(e($invoice->notes)) !!}
                </div>
                @endif

                @if($invoice->terms_and_conditions)
                <div class="notes-title" style="margin-top: 15px;">Terms & Conditions</div>
                <div class="notes-content">
                    {!! nl2br(e($invoice->terms_and_conditions)) !!}
                </div>
                @endif
            </div>
            <div class="totals-table-container">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal:</td>
                        <td>{{ number_format($invoice->subtotal, 2) }}</td>
                    </tr>
                    @if($invoice->discount_amount > 0)
                    <tr>
                        <td>Discount:</td>
                        <td>-{{ number_format($invoice->discount_amount, 2) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>Tax:</td>
                        <td>{{ number_format($invoice->tax_amount, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Total:</td>
                        <td>{{ $invoice->currency_code }} {{ number_format($invoice->total, 2) }}</td>
                    </tr>
                    @if($invoice->amount_paid > 0)
                    <tr>
                        <td>Amount Paid:</td>
                        <td>{{ number_format($invoice->amount_paid, 2) }}</td>
                    </tr>
                    @endif
                    @if($invoice->amount_due > 0)
                    <tr class="total-row balance-due">
                        <td>Balance Due:</td>
                        <td>{{ $invoice->currency_code }} {{ number_format($invoice->amount_due, 2) }}</td>
                    </tr>
                    @endif
                </table>
            </div>
        </div>

        <!-- QR Code for ZATCA/Compliance -->
        @if($invoice->compliance_qr_code)
        <div class="qr-code">
            <img src="data:image/png;base64,{{ $invoice->compliance_qr_code }}" alt="Compliance QR Code">
            @if($invoice->compliance_uuid)
            <div style="font-size: 8px; color: #666; margin-top: 5px;">
                UUID: {{ $invoice->compliance_uuid }}
            </div>
            @endif
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            Thank you for your business!<br>
            {{ $organization->legal_name ?? $organization->name }}
            @if($organization->website) | {{ $organization->website }} @endif
        </div>
    </div>
</body>
</html>

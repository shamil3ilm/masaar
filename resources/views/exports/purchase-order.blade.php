<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Purchase Order {{ $purchaseOrder->po_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #333; }
        .container { padding: 20px; }
        .header { display: table; width: 100%; margin-bottom: 30px; }
        .header-left, .header-right { display: table-cell; width: 50%; vertical-align: top; }
        .company-name { font-size: 20px; font-weight: bold; color: #8e44ad; margin-bottom: 5px; }
        .company-details { color: #666; font-size: 11px; }
        .po-title { text-align: right; font-size: 24px; font-weight: bold; color: #8e44ad; }
        .po-number { text-align: right; font-size: 14px; color: #666; }
        .divider { border-top: 2px solid #8e44ad; margin: 20px 0; }
        .info-section { display: table; width: 100%; margin-bottom: 20px; }
        .info-box { display: table-cell; width: 50%; vertical-align: top; padding-right: 20px; }
        .info-box-title { font-weight: bold; font-size: 11px; text-transform: uppercase; color: #666; margin-bottom: 5px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .info-content { padding: 5px 0; }
        .po-details { text-align: right; }
        .po-details table { margin-left: auto; }
        .po-details td { padding: 3px 10px; }
        .po-details td:first-child { color: #666; text-align: right; }
        .po-details td:last-child { font-weight: bold; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th { background: #8e44ad; color: #fff; padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase; }
        .items-table th:last-child, .items-table td:last-child { text-align: right; }
        .items-table td { padding: 10px; border-bottom: 1px solid #ddd; }
        .items-table tr:nth-child(even) { background: #f9f9f9; }
        .totals-section { display: table; width: 100%; }
        .notes-section { display: table-cell; width: 50%; vertical-align: top; }
        .totals-table-container { display: table-cell; width: 50%; vertical-align: top; }
        .totals-table { margin-left: auto; width: 250px; }
        .totals-table td { padding: 5px 10px; }
        .totals-table td:first-child { text-align: right; color: #666; }
        .totals-table td:last-child { text-align: right; font-weight: bold; }
        .total-row { background: #8e44ad; color: #fff; }
        .total-row td { padding: 10px !important; font-size: 14px; }
        .notes-title { font-weight: bold; font-size: 11px; text-transform: uppercase; color: #666; margin-bottom: 5px; }
        .notes-content { font-size: 10px; color: #666; padding: 10px; background: #f9f9f9; border-radius: 3px; }
        .shipping-box { margin-top: 20px; padding: 15px; background: #f3e8f7; border-left: 4px solid #8e44ad; }
        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 10px; border-top: 1px solid #ddd; padding-top: 15px; }
        .signature-section { margin-top: 40px; display: table; width: 100%; }
        .signature-box { display: table-cell; width: 45%; }
        .signature-line { border-top: 1px solid #333; margin-top: 40px; padding-top: 5px; font-size: 10px; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 10px; text-transform: uppercase; font-weight: bold; }
        .status-draft { background: #95a5a6; color: #fff; }
        .status-sent { background: #3498db; color: #fff; }
        .status-confirmed { background: #27ae60; color: #fff; }
        .status-partial { background: #f39c12; color: #fff; }
        .status-received { background: #27ae60; color: #fff; }
        .status-cancelled { background: #e74c3c; color: #fff; }
    </style>
</head>
<body>
    <div class="container">
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
                <div class="po-title">PURCHASE ORDER</div>
                <div class="po-number"># {{ $purchaseOrder->po_number }}</div>
                <div style="text-align: right; margin-top: 10px;">
                    <span class="status-badge status-{{ $purchaseOrder->status }}">{{ strtoupper($purchaseOrder->status) }}</span>
                </div>
            </div>
        </div>

        <div class="divider"></div>

        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Supplier</div>
                <div class="info-content">
                    <strong>{{ $purchaseOrder->supplier_name }}</strong><br>
                    @if($purchaseOrder->supplier_address)
                        {!! nl2br(e($purchaseOrder->supplier_address)) !!}<br>
                    @endif
                    @if($purchaseOrder->supplier_tax_number)
                        Tax No: {{ $purchaseOrder->supplier_tax_number }}<br>
                    @endif
                    @if($purchaseOrder->supplier_email)
                        {{ $purchaseOrder->supplier_email }}
                    @endif
                </div>
            </div>
            <div class="info-box po-details">
                <table>
                    <tr>
                        <td>PO Date:</td>
                        <td>{{ $purchaseOrder->order_date->format('M d, Y') }}</td>
                    </tr>
                    @if($purchaseOrder->expected_date)
                    <tr>
                        <td>Expected Delivery:</td>
                        <td>{{ $purchaseOrder->expected_date->format('M d, Y') }}</td>
                    </tr>
                    @endif
                    @if($purchaseOrder->reference)
                    <tr>
                        <td>Reference:</td>
                        <td>{{ $purchaseOrder->reference }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>Payment Terms:</td>
                        <td>{{ $purchaseOrder->payment_terms ?? 'Net 30' }} days</td>
                    </tr>
                    <tr>
                        <td>Currency:</td>
                        <td>{{ $purchaseOrder->currency_code }}</td>
                    </tr>
                </table>
            </div>
        </div>

        @if($purchaseOrder->shipping_address)
        <div class="shipping-box">
            <div class="notes-title">Ship To</div>
            <div style="font-size: 11px;">
                {!! nl2br(e($purchaseOrder->shipping_address)) !!}
            </div>
        </div>
        @endif

        <table class="items-table" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 35%">Description</th>
                    <th style="width: 12%">Qty</th>
                    <th style="width: 15%">Unit Price</th>
                    <th style="width: 10%">Tax</th>
                    <th style="width: 15%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $line)
                <tr>
                    <td>
                        {{ $line->description }}
                        @if($line->product && $line->product->sku)
                        <br><small style="color: #666;">SKU: {{ $line->product->sku }}</small>
                        @endif
                    </td>
                    <td>{{ number_format($line->quantity, 2) }}</td>
                    <td>{{ number_format($line->unit_price, 2) }}</td>
                    <td>{{ $line->tax_rate ?? 0 }}%</td>
                    <td>{{ number_format($line->total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-section">
            <div class="notes-section">
                @if($purchaseOrder->notes)
                <div class="notes-title">Notes to Supplier</div>
                <div class="notes-content">
                    {!! nl2br(e($purchaseOrder->notes)) !!}
                </div>
                @endif
            </div>
            <div class="totals-table-container">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal:</td>
                        <td>{{ number_format($purchaseOrder->subtotal, 2) }}</td>
                    </tr>
                    @if($purchaseOrder->discount_amount > 0)
                    <tr>
                        <td>Discount:</td>
                        <td>-{{ number_format($purchaseOrder->discount_amount, 2) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>Tax:</td>
                        <td>{{ number_format($purchaseOrder->tax_amount, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Total:</td>
                        <td>{{ $purchaseOrder->currency_code }} {{ number_format($purchaseOrder->total, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        @if($purchaseOrder->terms_and_conditions)
        <div class="shipping-box" style="margin-top: 20px;">
            <div class="notes-title">Terms & Conditions</div>
            <div style="font-size: 10px; color: #333;">
                {!! nl2br(e($purchaseOrder->terms_and_conditions)) !!}
            </div>
        </div>
        @endif

        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">Authorized Signature</div>
            </div>
            <div class="signature-box" style="text-align: right;">
                <div class="signature-line">Date</div>
            </div>
        </div>

        <div class="footer">
            Please reference PO number {{ $purchaseOrder->po_number }} on all invoices and correspondence.<br>
            {{ $organization->legal_name ?? $organization->name }}
            @if($organization->website) | {{ $organization->website }} @endif
        </div>
    </div>
</body>
</html>

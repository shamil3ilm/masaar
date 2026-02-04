<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Receipt {{ $invoice->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans Mono', 'Courier New', monospace;
            font-size: 10px;
            line-height: 1.2;
            color: #000;
            width: 80mm;
            padding: 3mm;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .divider { border-top: 1px dashed #000; margin: 5px 0; }
        .double-divider { border-top: 2px solid #000; margin: 5px 0; }
        .company-name { font-size: 14px; font-weight: bold; margin-bottom: 3px; }
        .receipt-title { font-size: 12px; font-weight: bold; margin: 5px 0; }
        .item-row { display: table; width: 100%; margin: 2px 0; }
        .item-name { display: table-cell; width: 50%; }
        .item-qty { display: table-cell; width: 15%; text-align: center; }
        .item-price { display: table-cell; width: 35%; text-align: right; }
        .total-row { display: table; width: 100%; margin: 2px 0; }
        .total-label { display: table-cell; width: 60%; }
        .total-value { display: table-cell; width: 40%; text-align: right; }
        .grand-total { font-size: 12px; font-weight: bold; }
        .qr-code { margin: 10px auto; width: 50mm; text-align: center; }
        .qr-code img { max-width: 100%; }
        .footer { font-size: 9px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="center">
        <div class="company-name">{{ $organization->name }}</div>
        @if($organization->address_line_1)
        <div>{{ $organization->address_line_1 }}</div>
        @endif
        @if($organization->city)
        <div>{{ $organization->city }}</div>
        @endif
        @if($organization->phone)
        <div>Tel: {{ $organization->phone }}</div>
        @endif
        @if($organization->tax_number)
        <div>Tax No: {{ $organization->tax_number }}</div>
        @endif
    </div>

    <div class="divider"></div>

    <div class="center receipt-title">
        {{ $invoice->invoice_type === 'simplified' ? 'SIMPLIFIED TAX INVOICE' : 'TAX INVOICE' }}
    </div>

    <div class="divider"></div>

    <div>
        <div class="item-row">
            <div class="item-name">Invoice No:</div>
            <div class="item-price">{{ $invoice->invoice_number }}</div>
        </div>
        <div class="item-row">
            <div class="item-name">Date:</div>
            <div class="item-price">{{ $invoice->invoice_date->format('d/m/Y H:i') }}</div>
        </div>
        @if($invoice->customer_name)
        <div class="item-row">
            <div class="item-name">Customer:</div>
            <div class="item-price">{{ Str::limit($invoice->customer_name, 20) }}</div>
        </div>
        @endif
        @if($invoice->customer_tax_number)
        <div class="item-row">
            <div class="item-name">Tax No:</div>
            <div class="item-price">{{ $invoice->customer_tax_number }}</div>
        </div>
        @endif
    </div>

    <div class="double-divider"></div>

    <div class="item-row bold">
        <div class="item-name">Item</div>
        <div class="item-qty">Qty</div>
        <div class="item-price">Amount</div>
    </div>

    <div class="divider"></div>

    @foreach($lines as $line)
    <div>
        <div>{{ Str::limit($line->description, 30) }}</div>
        <div class="item-row">
            <div class="item-name">@ {{ number_format($line->unit_price, 2) }}</div>
            <div class="item-qty">{{ number_format($line->quantity, 0) }}</div>
            <div class="item-price">{{ number_format($line->total, 2) }}</div>
        </div>
    </div>
    @endforeach

    <div class="double-divider"></div>

    <div class="total-row">
        <div class="total-label">Subtotal:</div>
        <div class="total-value">{{ number_format($invoice->subtotal, 2) }}</div>
    </div>

    @if($invoice->discount_amount > 0)
    <div class="total-row">
        <div class="total-label">Discount:</div>
        <div class="total-value">-{{ number_format($invoice->discount_amount, 2) }}</div>
    </div>
    @endif

    <div class="total-row">
        <div class="total-label">VAT ({{ $invoice->lines->first()?->tax_rate ?? 15 }}%):</div>
        <div class="total-value">{{ number_format($invoice->tax_amount, 2) }}</div>
    </div>

    <div class="divider"></div>

    <div class="total-row grand-total">
        <div class="total-label">TOTAL {{ $invoice->currency_code }}:</div>
        <div class="total-value">{{ number_format($invoice->total, 2) }}</div>
    </div>

    @if($invoice->amount_paid > 0)
    <div class="total-row">
        <div class="total-label">Paid:</div>
        <div class="total-value">{{ number_format($invoice->amount_paid, 2) }}</div>
    </div>
    <div class="total-row bold">
        <div class="total-label">Balance:</div>
        <div class="total-value">{{ number_format($invoice->amount_due, 2) }}</div>
    </div>
    @endif

    <div class="divider"></div>

    @if($invoice->compliance_qr_code)
    <div class="qr-code">
        <img src="data:image/png;base64,{{ $invoice->compliance_qr_code }}" alt="QR Code">
    </div>
    @endif

    <div class="center footer">
        <div>Thank you for your business!</div>
        @if($invoice->compliance_uuid)
        <div style="font-size: 8px; margin-top: 3px;">
            UUID: {{ Str::limit($invoice->compliance_uuid, 36) }}
        </div>
        @endif
        <div class="divider"></div>
        <div>{{ $organization->name }}</div>
    </div>
</body>
</html>

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
            font-size: 9px;
            line-height: 1.2;
            color: #000;
            width: 58mm;
            padding: 2mm;
            background: #fff;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .small { font-size: 8px; }
        .large { font-size: 11px; }

        .divider { border-top: 1px dashed #000; margin: 4px 0; }
        .double-divider { border-top: 2px solid #000; margin: 4px 0; }

        .company-name { font-size: 12px; font-weight: bold; }
        .receipt-title { font-size: 10px; font-weight: bold; margin: 4px 0; }

        .row { display: table; width: 100%; margin: 1px 0; }
        .col-left { display: table-cell; width: 50%; }
        .col-right { display: table-cell; width: 50%; text-align: right; }

        .item-row { margin: 3px 0; }
        .item-name { font-size: 9px; }
        .item-line { display: table; width: 100%; }
        .item-qty { display: table-cell; width: 55%; font-size: 8px; }
        .item-amt { display: table-cell; width: 45%; text-align: right; font-size: 9px; }

        .total-row { display: table; width: 100%; margin: 2px 0; }
        .total-label { display: table-cell; width: 50%; font-size: 9px; }
        .total-value { display: table-cell; width: 50%; text-align: right; font-weight: bold; font-size: 9px; }

        .grand-total { font-size: 12px; font-weight: bold; }
        .grand-total .total-label, .grand-total .total-value { font-size: 11px; }

        .qr-code { margin: 6px auto; text-align: center; }
        .qr-code img { max-width: 35mm; }

        .footer { margin-top: 6px; font-size: 8px; }

        @media print {
            body { width: 58mm; margin: 0; padding: 1mm; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="center">
        <div class="company-name">{{ Str::limit($organization->name, 20) }}</div>
        @if($organization->address_line_1)
        <div class="small">{{ Str::limit($organization->address_line_1, 28) }}</div>
        @endif
        @if($organization->phone)
        <div class="small">{{ $organization->phone }}</div>
        @endif
        @if($organization->tax_number)
        <div class="small bold">TAX: {{ $organization->tax_number }}</div>
        @endif
    </div>

    <div class="divider"></div>

    <div class="center receipt-title">
        @if($invoice->invoice_type === 'simplified')SIMPLIFIED INVOICE
        @elseif($invoice->invoice_type === 'credit_note')CREDIT NOTE
        @else TAX INVOICE
        @endif
    </div>

    <div class="divider"></div>

    <!-- Invoice Info -->
    <div class="row small">
        <div class="col-left">No:</div>
        <div class="col-right bold">{{ $invoice->invoice_number }}</div>
    </div>
    <div class="row small">
        <div class="col-left">Date:</div>
        <div class="col-right">{{ $invoice->invoice_date->format('d/m/y H:i') }}</div>
    </div>
    @if($invoice->customer_name && $invoice->customer_name !== 'Walk-in Customer')
    <div class="row small">
        <div class="col-left">Cust:</div>
        <div class="col-right">{{ Str::limit($invoice->customer_name, 14) }}</div>
    </div>
    @endif

    <div class="double-divider"></div>

    <!-- Items -->
    @foreach($lines as $line)
    <div class="item-row">
        <div class="item-name">{{ Str::limit($line->description, 24) }}</div>
        <div class="item-line">
            <div class="item-qty">{{ number_format((float)$line->quantity, 0) }} x {{ number_format((float)$line->unit_price, 2) }}</div>
            <div class="item-amt">{{ number_format((float)$line->total, 2) }}</div>
        </div>
    </div>
    @endforeach

    <div class="double-divider"></div>

    <!-- Totals -->
    <div class="total-row">
        <div class="total-label">Subtotal:</div>
        <div class="total-value">{{ number_format((float)$invoice->subtotal, 2) }}</div>
    </div>
    @if((float)($invoice->discount_amount ?? 0) > 0)
    <div class="total-row">
        <div class="total-label">Discount:</div>
        <div class="total-value">-{{ number_format((float)$invoice->discount_amount, 2) }}</div>
    </div>
    @endif
    <div class="total-row">
        <div class="total-label">Tax:</div>
        <div class="total-value">{{ number_format((float)$invoice->tax_amount, 2) }}</div>
    </div>

    <div class="divider"></div>

    <div class="total-row grand-total">
        <div class="total-label">TOTAL:</div>
        <div class="total-value">{{ $invoice->currency_code }} {{ number_format((float)$invoice->total, 2) }}</div>
    </div>

    @if((float)($invoice->amount_paid ?? 0) > 0)
    <div class="total-row small">
        <div class="total-label">Paid:</div>
        <div class="total-value">{{ number_format((float)$invoice->amount_paid, 2) }}</div>
    </div>
    @if((float)($invoice->amount_due ?? 0) > 0)
    <div class="total-row small">
        <div class="total-label">Due:</div>
        <div class="total-value">{{ number_format((float)$invoice->amount_due, 2) }}</div>
    </div>
    @endif
    @endif

    <div class="divider"></div>

    <!-- QR Code -->
    @if($showQrCode && $invoice->compliance_qr_code)
    <div class="qr-code">
        <img src="data:image/png;base64,{{ $invoice->compliance_qr_code }}" alt="QR">
    </div>
    @endif

    <!-- Footer -->
    <div class="center footer">
        <div class="bold">Thank you!</div>
        @if($invoice->compliance_uuid)
        <div class="small" style="word-break: break-all;">{{ Str::limit($invoice->compliance_uuid, 30) }}</div>
        @endif
        <div class="divider"></div>
        <div class="small">{{ now()->format('d/m/y H:i') }}</div>
    </div>

    <div style="height: 10mm;"></div>
</body>
</html>

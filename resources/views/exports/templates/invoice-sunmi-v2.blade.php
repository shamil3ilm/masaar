{{--
    Sunmi V2 Template (58mm / 384 dots width)
    - Paper width: 58mm
    - Printable area: ~48mm
    - Max chars per line: 32 (at 12x24 font)
    - Supports: QR codes, barcodes, images
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: monospace;
            font-size: 10px;
            line-height: 1.25;
            color: #000;
            width: 48mm;
            margin: 0 auto;
            padding: 2mm 0;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .underline { text-decoration: underline; }

        /* Sunmi-specific sizes matching thermal printer fonts */
        .text-xs { font-size: 8px; }
        .text-sm { font-size: 9px; }
        .text-md { font-size: 10px; }
        .text-lg { font-size: 12px; }
        .text-xl { font-size: 14px; }
        .text-2xl { font-size: 16px; }

        .sep { border-top: 1px dashed #000; margin: 4px 0; }
        .sep-bold { border-top: 2px solid #000; margin: 4px 0; }

        .header { margin-bottom: 4px; }
        .shop-name { font-size: 14px; font-weight: bold; }

        .flex { display: table; width: 100%; }
        .flex-left { display: table-cell; text-align: left; }
        .flex-right { display: table-cell; text-align: right; }

        .item { margin: 3px 0; }
        .item-name { display: block; }
        .item-detail { display: table; width: 100%; }
        .item-qty { display: table-cell; width: 60%; }
        .item-price { display: table-cell; width: 40%; text-align: right; }

        .totals { margin: 4px 0; }
        .total-line { display: table; width: 100%; margin: 2px 0; }
        .total-label { display: table-cell; width: 55%; }
        .total-amount { display: table-cell; width: 45%; text-align: right; font-weight: bold; }

        .grand-total .total-label,
        .grand-total .total-amount { font-size: 13px; }

        .qr { text-align: center; margin: 8px 0; }
        .qr img { width: 30mm; height: auto; }

        .footer { margin-top: 6px; font-size: 8px; text-align: center; }

        /* Sunmi SDK specific markers - these are processed by the Sunmi print SDK */
        .sunmi-barcode { text-align: center; margin: 4px 0; }
        .sunmi-cut { page-break-after: always; }
    </style>
</head>
<body>
    <div class="header center">
        <div class="shop-name">{{ Str::upper(Str::limit($organization->name, 18)) }}</div>
        @if($organization->address_line_1)
        <div class="text-xs">{{ Str::limit($organization->address_line_1, 28) }}</div>
        @endif
        @if($organization->phone)
        <div class="text-xs">Tel: {{ $organization->phone }}</div>
        @endif
        @if($organization->tax_number)
        <div class="text-sm bold">VAT: {{ $organization->tax_number }}</div>
        @endif
    </div>

    <div class="sep"></div>

    <div class="center text-lg bold">
        {{ $invoice->invoice_type === 'simplified' ? 'TAX INVOICE' : 'INVOICE' }}
    </div>

    <div class="sep"></div>

    <div class="flex text-sm">
        <div class="flex-left">No:</div>
        <div class="flex-right bold">{{ $invoice->invoice_number }}</div>
    </div>
    <div class="flex text-sm">
        <div class="flex-left">Date:</div>
        <div class="flex-right">{{ $invoice->invoice_date->format('d/m/y H:i') }}</div>
    </div>
    @if($invoice->customer_name !== 'Walk-in Customer')
    <div class="flex text-sm">
        <div class="flex-left">Customer:</div>
        <div class="flex-right">{{ Str::limit($invoice->customer_name, 14) }}</div>
    </div>
    @endif

    <div class="sep-bold"></div>

    <div class="flex text-xs bold">
        <div class="flex-left">ITEM</div>
        <div class="flex-right">AMOUNT</div>
    </div>

    <div class="sep"></div>

    @foreach($lines as $line)
    <div class="item">
        <span class="item-name text-sm">{{ Str::limit($line->description, 24) }}</span>
        <div class="item-detail text-xs">
            <span class="item-qty">{{ (int)$line->quantity }}x{{ number_format((float)$line->unit_price, 2) }}</span>
            <span class="item-price">{{ number_format((float)$line->total, 2) }}</span>
        </div>
    </div>
    @endforeach

    <div class="sep-bold"></div>

    <div class="totals">
        <div class="total-line text-sm">
            <span class="total-label">Subtotal</span>
            <span class="total-amount">{{ number_format((float)$invoice->subtotal, 2) }}</span>
        </div>
        @if((float)$invoice->discount_amount > 0)
        <div class="total-line text-sm">
            <span class="total-label">Discount</span>
            <span class="total-amount">-{{ number_format((float)$invoice->discount_amount, 2) }}</span>
        </div>
        @endif
        <div class="total-line text-sm">
            <span class="total-label">VAT</span>
            <span class="total-amount">{{ number_format((float)$invoice->tax_amount, 2) }}</span>
        </div>

        <div class="sep"></div>

        <div class="total-line grand-total">
            <span class="total-label bold">TOTAL</span>
            <span class="total-amount">{{ $invoice->currency_code }}{{ number_format((float)$invoice->total, 2) }}</span>
        </div>

        @if((float)$invoice->amount_paid > 0)
        <div class="total-line text-sm">
            <span class="total-label">Paid</span>
            <span class="total-amount">{{ number_format((float)$invoice->amount_paid, 2) }}</span>
        </div>
        @if((float)$invoice->change_amount > 0)
        <div class="total-line text-sm">
            <span class="total-label">Change</span>
            <span class="total-amount">{{ number_format((float)$invoice->change_amount, 2) }}</span>
        </div>
        @endif
        @endif
    </div>

    <div class="sep"></div>

    @if($showQrCode && $invoice->compliance_qr_code)
    <div class="qr">
        <img src="data:image/png;base64,{{ $invoice->compliance_qr_code }}" alt="QR">
    </div>
    @endif

    <div class="footer">
        <div class="bold">Thank you!</div>
        @if($invoice->compliance_uuid)
        <div class="text-xs" style="word-break: break-all;">
            {{ Str::limit($invoice->compliance_uuid, 28) }}
        </div>
        @endif
        <div class="sep"></div>
        <div>{{ $organization->name }}</div>
        <div class="text-xs">{{ now()->format('d/m/Y H:i') }}</div>
    </div>

    <!-- Space for paper cut -->
    <div style="height: 8mm;"></div>
</body>
</html>

{{--
    Sunmi V2 Pro Template (80mm / 576 dots width)
    - Paper width: 80mm
    - Printable area: ~72mm
    - Max chars per line: 48 (at 12x24 font)
    - Supports: QR codes, barcodes, images, NFC
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $invoice->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: monospace;
            font-size: 11px;
            line-height: 1.3;
            color: #000;
            width: 72mm;
            margin: 0 auto;
            padding: 3mm 0;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .left { text-align: left; }
        .bold { font-weight: bold; }

        .text-xs { font-size: 9px; }
        .text-sm { font-size: 10px; }
        .text-md { font-size: 11px; }
        .text-lg { font-size: 13px; }
        .text-xl { font-size: 15px; }
        .text-2xl { font-size: 18px; }

        .sep { border-top: 1px dashed #000; margin: 5px 0; }
        .sep-bold { border-top: 2px solid #000; margin: 5px 0; }
        .sep-double { border-top: 3px double #000; margin: 5px 0; }

        .header { margin-bottom: 5px; }
        .shop-name { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
        .shop-info { font-size: 10px; color: #333; }

        .flex { display: table; width: 100%; }
        .flex-left { display: table-cell; text-align: left; }
        .flex-right { display: table-cell; text-align: right; }
        .flex-center { display: table-cell; text-align: center; }

        .doc-title { font-size: 14px; font-weight: bold; letter-spacing: 2px; padding: 4px 0; }

        .info-grid { margin: 4px 0; }
        .info-row { display: table; width: 100%; margin: 2px 0; }
        .info-label { display: table-cell; width: 35%; color: #555; }
        .info-value { display: table-cell; width: 65%; text-align: right; font-weight: 500; }

        .items { margin: 5px 0; }
        .item { margin: 4px 0; padding-bottom: 4px; border-bottom: 1px dotted #ccc; }
        .item:last-child { border-bottom: none; }
        .item-name { font-weight: 500; }
        .item-sku { font-size: 9px; color: #666; }
        .item-line { display: table; width: 100%; margin-top: 2px; }
        .item-qty { display: table-cell; width: 35%; font-size: 10px; }
        .item-unit { display: table-cell; width: 30%; font-size: 10px; text-align: center; }
        .item-total { display: table-cell; width: 35%; text-align: right; font-weight: bold; }

        .totals { margin: 6px 0; }
        .total-row { display: table; width: 100%; margin: 3px 0; }
        .total-label { display: table-cell; width: 55%; font-size: 11px; }
        .total-value { display: table-cell; width: 45%; text-align: right; font-weight: bold; font-size: 11px; }

        .grand-total {
            background: #f0f0f0;
            padding: 6px;
            margin: 4px -3mm;
            width: calc(100% + 6mm);
        }
        .grand-total .total-label { font-size: 14px; font-weight: bold; }
        .grand-total .total-value { font-size: 16px; }

        .balance-due {
            background: #ffe0e0;
        }

        .payment-box {
            background: #f5f5f5;
            padding: 4px;
            margin: 4px 0;
            border-radius: 2px;
        }

        .qr-section { text-align: center; margin: 10px 0; }
        .qr-section img { width: 40mm; height: auto; }
        .qr-label { font-size: 8px; color: #666; margin-top: 3px; }

        .footer { margin-top: 8px; text-align: center; }
        .footer-thanks { font-size: 12px; font-weight: bold; margin-bottom: 4px; }
        .footer-info { font-size: 9px; color: #555; }

        .barcode-section { text-align: center; margin: 6px 0; }

        /* RTL Support */
        .rtl { direction: rtl; }
        .rtl .flex-left { text-align: right; }
        .rtl .flex-right { text-align: left; }
        .rtl .info-value { text-align: left; }
        .rtl .item-total { text-align: left; }
        .rtl .total-value { text-align: left; }
    </style>
</head>
<body class="{{ $organization->language === 'ar' ? 'rtl' : '' }}">
    <!-- Header -->
    <div class="header center">
        @if($showLogo && $organization->logo_url)
        <img src="{{ $organization->logo_url }}" alt="" style="max-height: 35px; margin-bottom: 4px;">
        @endif
        <div class="shop-name">{{ Str::upper($organization->name) }}</div>
        <div class="shop-info">
            @if($organization->address_line_1){{ $organization->address_line_1 }}<br>@endif
            @if($organization->city){{ $organization->city }}@if($organization->postal_code), {{ $organization->postal_code }}@endif<br>@endif
            @if($organization->phone)Tel: {{ $organization->phone }}<br>@endif
            @if($organization->tax_number)
                <span class="bold">
                @if($organization->country_code === 'IN')GSTIN: @elseif($organization->country_code === 'SA')VAT: @else TRN: @endif
                {{ $organization->tax_number }}
                </span>
            @endif
        </div>
    </div>

    <div class="sep-bold"></div>

    <!-- Document Title -->
    <div class="center doc-title">
        @if($invoice->invoice_type === 'simplified')
            SIMPLIFIED TAX INVOICE
        @elseif($invoice->invoice_type === 'credit_note')
            CREDIT NOTE
        @elseif($invoice->invoice_type === 'debit_note')
            DEBIT NOTE
        @else
            TAX INVOICE
        @endif
    </div>

    <div class="sep"></div>

    <!-- Invoice Info -->
    <div class="info-grid">
        <div class="info-row">
            <span class="info-label">Invoice No:</span>
            <span class="info-value bold">{{ $invoice->invoice_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Date:</span>
            <span class="info-value">{{ $invoice->invoice_date->format('d/m/Y H:i') }}</span>
        </div>
        @if($invoice->cashier_name ?? null)
        <div class="info-row">
            <span class="info-label">Cashier:</span>
            <span class="info-value">{{ $invoice->cashier_name }}</span>
        </div>
        @endif
        @if($invoice->customer_name && $invoice->customer_name !== 'Walk-in Customer')
        <div class="info-row">
            <span class="info-label">Customer:</span>
            <span class="info-value">{{ Str::limit($invoice->customer_name, 25) }}</span>
        </div>
        @endif
        @if($invoice->customer_tax_number)
        <div class="info-row">
            <span class="info-label">@if($organization->country_code === 'IN')GSTIN:@else TRN:@endif</span>
            <span class="info-value">{{ $invoice->customer_tax_number }}</span>
        </div>
        @endif
    </div>

    <div class="sep-double"></div>

    <!-- Column Headers -->
    <div class="flex text-sm bold">
        <span class="flex-left" style="width: 50%;">ITEM</span>
        <span class="flex-center" style="width: 20%;">QTY</span>
        <span class="flex-right" style="width: 30%;">AMOUNT</span>
    </div>

    <div class="sep"></div>

    <!-- Line Items -->
    <div class="items">
        @foreach($lines as $line)
        <div class="item">
            <div class="item-name">{{ Str::limit($line->description, 40) }}</div>
            @if($line->product?->sku)
            <div class="item-sku">SKU: {{ $line->product->sku }}</div>
            @endif
            <div class="item-line">
                <span class="item-qty">
                    {{ number_format((float)$line->quantity, $line->quantity == floor($line->quantity) ? 0 : 2) }}
                    {{ $line->unit?->symbol ?? '' }}
                </span>
                <span class="item-unit">@ {{ number_format((float)$line->unit_price, 2) }}</span>
                <span class="item-total">{{ number_format((float)$line->total, 2) }}</span>
            </div>
            @if((float)($line->discount_amount ?? 0) > 0)
            <div class="text-xs" style="color: #666; text-align: right;">
                Disc: -{{ number_format((float)$line->discount_amount, 2) }}
            </div>
            @endif
        </div>
        @endforeach
    </div>

    <div class="sep-double"></div>

    <!-- Totals -->
    <div class="totals">
        <div class="total-row">
            <span class="total-label">Subtotal:</span>
            <span class="total-value">{{ number_format((float)$invoice->subtotal, 2) }}</span>
        </div>

        @if((float)($invoice->discount_amount ?? 0) > 0)
        <div class="total-row">
            <span class="total-label">Discount:</span>
            <span class="total-value">-{{ number_format((float)$invoice->discount_amount, 2) }}</span>
        </div>
        @endif

        @if($organization->country_code === 'IN')
            @php
                $cgst = $lines->sum('cgst_amount');
                $sgst = $lines->sum('sgst_amount');
                $igst = $lines->sum('igst_amount');
            @endphp
            @if((float)$igst > 0)
            <div class="total-row">
                <span class="total-label">IGST:</span>
                <span class="total-value">{{ number_format((float)$igst, 2) }}</span>
            </div>
            @else
            <div class="total-row">
                <span class="total-label">CGST:</span>
                <span class="total-value">{{ number_format((float)$cgst, 2) }}</span>
            </div>
            <div class="total-row">
                <span class="total-label">SGST:</span>
                <span class="total-value">{{ number_format((float)$sgst, 2) }}</span>
            </div>
            @endif
        @else
        <div class="total-row">
            <span class="total-label">VAT ({{ $lines->first()?->tax_rate ?? 15 }}%):</span>
            <span class="total-value">{{ number_format((float)$invoice->tax_amount, 2) }}</span>
        </div>
        @endif

        <div class="total-row grand-total">
            <span class="total-label">TOTAL {{ $invoice->currency_code }}:</span>
            <span class="total-value">{{ number_format((float)$invoice->total, 2) }}</span>
        </div>
    </div>

    <!-- Payment Info -->
    @if((float)($invoice->amount_paid ?? 0) > 0 || $invoice->payment_method)
    <div class="payment-box">
        @if($invoice->payment_method)
        <div class="total-row">
            <span class="total-label">Payment Method:</span>
            <span class="total-value">{{ ucfirst($invoice->payment_method) }}</span>
        </div>
        @endif
        @if((float)($invoice->amount_paid ?? 0) > 0)
        <div class="total-row">
            <span class="total-label">Amount Paid:</span>
            <span class="total-value">{{ number_format((float)$invoice->amount_paid, 2) }}</span>
        </div>
        @endif
        @if((float)($invoice->amount_due ?? 0) > 0 && $invoice->status !== 'paid')
        <div class="total-row" style="color: #c00;">
            <span class="total-label bold">Balance Due:</span>
            <span class="total-value">{{ number_format((float)$invoice->amount_due, 2) }}</span>
        </div>
        @endif
        @if((float)($invoice->change_amount ?? 0) > 0)
        <div class="total-row">
            <span class="total-label">Change:</span>
            <span class="total-value">{{ number_format((float)$invoice->change_amount, 2) }}</span>
        </div>
        @endif
    </div>
    @endif

    <div class="sep"></div>

    <!-- QR Code -->
    @if($showQrCode && $invoice->compliance_qr_code)
    <div class="qr-section">
        <img src="data:image/png;base64,{{ $invoice->compliance_qr_code }}" alt="QR Code">
        @if($invoice->compliance_uuid)
        <div class="qr-label">UUID: {{ Str::limit($invoice->compliance_uuid, 36) }}</div>
        @endif
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <div class="footer-thanks">Thank you for your business!</div>
        <div class="sep"></div>
        <div class="footer-info">
            {{ $organization->name }}<br>
            @if($organization->website){{ $organization->website }}<br>@endif
            Printed: {{ now()->format('d/m/Y H:i:s') }}
            @if(($invoice->print_count ?? 0) > 1)
            <br><span class="bold">COPY #{{ $invoice->print_count }}</span>
            @endif
        </div>
    </div>

    <!-- Space for paper cut -->
    <div style="height: 12mm;"></div>
</body>
</html>

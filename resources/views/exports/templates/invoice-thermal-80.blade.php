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
            font-size: 11px;
            line-height: 1.3;
            color: #000;
            width: 80mm;
            padding: 3mm;
            background: #fff;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .left { text-align: left; }
        .bold { font-weight: bold; }
        .small { font-size: 9px; }
        .large { font-size: 14px; }
        .x-large { font-size: 16px; }

        .divider { border-top: 1px dashed #000; margin: 6px 0; }
        .double-divider { border-top: 2px solid #000; margin: 6px 0; }
        .dotted-divider { border-top: 1px dotted #000; margin: 4px 0; }

        .company-name { font-size: 16px; font-weight: bold; margin-bottom: 4px; }
        .receipt-title { font-size: 13px; font-weight: bold; margin: 6px 0; letter-spacing: 1px; }

        .row { display: table; width: 100%; margin: 2px 0; }
        .col-left { display: table-cell; width: 55%; vertical-align: top; }
        .col-right { display: table-cell; width: 45%; text-align: right; vertical-align: top; }
        .col-center { display: table-cell; width: 100%; text-align: center; }

        .item-row { margin: 4px 0; }
        .item-name { font-weight: 500; }
        .item-details { display: table; width: 100%; }
        .item-qty-price { display: table-cell; width: 60%; }
        .item-total { display: table-cell; width: 40%; text-align: right; }

        .total-section { margin-top: 4px; }
        .total-row { display: table; width: 100%; margin: 3px 0; }
        .total-label { display: table-cell; width: 55%; }
        .total-value { display: table-cell; width: 45%; text-align: right; font-weight: bold; }

        .grand-total { font-size: 16px; font-weight: bold; padding: 6px 0; }
        .grand-total .total-label { font-size: 14px; }
        .grand-total .total-value { font-size: 16px; }

        .payment-info { background: #f5f5f5; padding: 4px; margin: 6px 0; }

        .qr-code { margin: 10px auto; text-align: center; }
        .qr-code img { max-width: 45mm; height: auto; }

        .footer { margin-top: 10px; font-size: 9px; }
        .barcode { text-align: center; margin: 8px 0; }
        .barcode img { max-width: 60mm; }

        /* Arabic/RTL Support */
        .rtl { direction: rtl; text-align: right; }
        .rtl .col-left { text-align: right; }
        .rtl .col-right { text-align: left; }

        @media print {
            body { width: 80mm; margin: 0; padding: 2mm; }
            .no-print { display: none; }
        }
    </style>
</head>
<body class="{{ $organization->language === 'ar' ? 'rtl' : '' }}">
    <!-- Company Header -->
    <div class="center">
        @if($showLogo && $organization->logo_url)
        <img src="{{ $organization->logo_url }}" alt="" style="max-height: 30px; margin-bottom: 4px;">
        @endif
        <div class="company-name">{{ $organization->name }}</div>
        @if($organization->address_line_1)
        <div class="small">{{ $organization->address_line_1 }}</div>
        @endif
        @if($organization->city)
        <div class="small">{{ $organization->city }}@if($organization->postal_code), {{ $organization->postal_code }}@endif</div>
        @endif
        @if($organization->phone)
        <div class="small">Tel: {{ $organization->phone }}</div>
        @endif
        @if($organization->tax_number)
        <div class="small bold">
            @if($organization->country_code === 'IN')GSTIN: @elseif($organization->country_code === 'SA')VAT: @else TRN: @endif
            {{ $organization->tax_number }}
        </div>
        @endif
    </div>

    <div class="divider"></div>

    <!-- Receipt Title -->
    <div class="center receipt-title">
        @if($invoice->invoice_type === 'simplified')
            SIMPLIFIED TAX INVOICE
        @elseif($invoice->invoice_type === 'credit_note')
            CREDIT NOTE
        @else
            TAX INVOICE
        @endif
    </div>

    <div class="divider"></div>

    <!-- Invoice Details -->
    <div class="row">
        <div class="col-left">Invoice No:</div>
        <div class="col-right bold">{{ $invoice->invoice_number }}</div>
    </div>
    <div class="row">
        <div class="col-left">Date:</div>
        <div class="col-right">{{ $invoice->invoice_date->format('d/m/Y H:i') }}</div>
    </div>
    @if($invoice->cashier_name ?? false)
    <div class="row">
        <div class="col-left">Cashier:</div>
        <div class="col-right">{{ $invoice->cashier_name }}</div>
    </div>
    @endif
    @if($invoice->customer_name && $invoice->customer_name !== 'Walk-in Customer')
    <div class="row">
        <div class="col-left">Customer:</div>
        <div class="col-right">{{ Str::limit($invoice->customer_name, 22) }}</div>
    </div>
    @endif
    @if($invoice->customer_tax_number)
    <div class="row">
        <div class="col-left">@if($organization->country_code === 'IN')GSTIN:@else TRN:@endif</div>
        <div class="col-right">{{ $invoice->customer_tax_number }}</div>
    </div>
    @endif

    <div class="double-divider"></div>

    <!-- Column Headers -->
    <div class="row bold small">
        <div class="col-left">ITEM</div>
        <div class="col-right">AMOUNT</div>
    </div>

    <div class="dotted-divider"></div>

    <!-- Line Items -->
    @foreach($lines as $line)
    <div class="item-row">
        <div class="item-name">{{ Str::limit($line->description, 35) }}</div>
        <div class="item-details">
            <div class="item-qty-price small">
                {{ number_format((float)$line->quantity, $line->quantity == floor($line->quantity) ? 0 : 2) }}
                x {{ number_format((float)$line->unit_price, 2) }}
                @if((float)($line->discount_amount ?? 0) > 0)
                <br><span style="color: #666;">Disc: -{{ number_format((float)$line->discount_amount, 2) }}</span>
                @endif
            </div>
            <div class="item-total">{{ number_format((float)$line->total, 2) }}</div>
        </div>
    </div>
    @endforeach

    <div class="double-divider"></div>

    <!-- Totals -->
    <div class="total-section">
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

        @if($organization->country_code === 'IN')
            @php
                $cgst = $lines->sum('cgst_amount');
                $sgst = $lines->sum('sgst_amount');
                $igst = $lines->sum('igst_amount');
            @endphp
            @if($igst > 0)
            <div class="total-row">
                <div class="total-label">IGST:</div>
                <div class="total-value">{{ number_format((float)$igst, 2) }}</div>
            </div>
            @else
            <div class="total-row">
                <div class="total-label">CGST:</div>
                <div class="total-value">{{ number_format((float)$cgst, 2) }}</div>
            </div>
            <div class="total-row">
                <div class="total-label">SGST:</div>
                <div class="total-value">{{ number_format((float)$sgst, 2) }}</div>
            </div>
            @endif
        @else
        <div class="total-row">
            <div class="total-label">VAT ({{ $lines->first()?->tax_rate ?? config("regional.{$organization->country_code}.tax_rates.standard", 15) }}%):</div>
            <div class="total-value">{{ number_format((float)$invoice->tax_amount, 2) }}</div>
        </div>
        @endif

        <div class="divider"></div>

        <div class="total-row grand-total">
            <div class="total-label">TOTAL {{ $invoice->currency_code }}:</div>
            <div class="total-value">{{ number_format((float)$invoice->total, 2) }}</div>
        </div>
    </div>

    <!-- Payment Details -->
    @if((float)($invoice->amount_paid ?? 0) > 0 || $invoice->payment_method)
    <div class="payment-info">
        @if($invoice->payment_method)
        <div class="row">
            <div class="col-left">Payment:</div>
            <div class="col-right">{{ ucfirst($invoice->payment_method) }}</div>
        </div>
        @endif
        @if((float)($invoice->amount_paid ?? 0) > 0)
        <div class="row">
            <div class="col-left">Paid:</div>
            <div class="col-right bold">{{ number_format((float)$invoice->amount_paid, 2) }}</div>
        </div>
        @endif
        @if((float)($invoice->amount_due ?? 0) > 0 && $invoice->status !== 'paid')
        <div class="row">
            <div class="col-left">Balance:</div>
            <div class="col-right bold">{{ number_format((float)$invoice->amount_due, 2) }}</div>
        </div>
        @endif
        @if((float)($invoice->change_amount ?? 0) > 0)
        <div class="row">
            <div class="col-left">Change:</div>
            <div class="col-right bold">{{ number_format((float)$invoice->change_amount, 2) }}</div>
        </div>
        @endif
    </div>
    @endif

    <div class="divider"></div>

    <!-- QR Code (ZATCA/Compliance) -->
    @if($showQrCode && $invoice->compliance_qr_code)
    <div class="qr-code">
        <img src="data:image/png;base64,{{ $invoice->compliance_qr_code }}" alt="QR Code">
    </div>
    @endif

    <!-- Footer -->
    <div class="center footer">
        <div class="bold">Thank you for your business!</div>
        @if($invoice->compliance_uuid)
        <div class="small" style="margin-top: 4px; word-break: break-all;">
            UUID: {{ $invoice->compliance_uuid }}
        </div>
        @endif
        <div class="divider"></div>
        <div>{{ $organization->name }}</div>
        @if($organization->website)
        <div class="small">{{ $organization->website }}</div>
        @endif
        <div class="small" style="margin-top: 6px;">
            Printed: {{ now()->format('d/m/Y H:i:s') }}
        </div>
        @if($invoice->print_count > 1)
        <div class="small bold">COPY #{{ $invoice->print_count }}</div>
        @endif
    </div>

    <!-- Extra space for paper cut -->
    <div style="height: 15mm;"></div>
</body>
</html>

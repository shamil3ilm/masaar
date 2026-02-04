<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 11px;
            line-height: 1.3;
            width: 80mm;
            padding: 3mm;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .small { font-size: 9px; }
        .large { font-size: 14px; }

        .sep { border-top: 1px dashed #000; margin: 5px 0; }
        .sep-bold { border-top: 2px solid #000; margin: 5px 0; }

        .company-name { font-size: 14px; font-weight: bold; }

        .row { display: table; width: 100%; margin: 2px 0; }
        .col-left { display: table-cell; width: 45%; }
        .col-right { display: table-cell; width: 55%; text-align: right; }

        .amount-box { background: #000; color: #fff; padding: 10px; margin: 8px 0; text-align: center; }
        .amount-label { font-size: 10px; }
        .amount-value { font-size: 20px; font-weight: bold; margin-top: 3px; }

        .invoice-row { margin: 3px 0; font-size: 10px; }

        .footer { margin-top: 10px; font-size: 9px; }
    </style>
</head>
<body>
    <div class="center">
        <div class="company-name">{{ $organization->name }}</div>
        @if($organization->address_line_1)
        <div class="small">{{ $organization->address_line_1 }}</div>
        @endif
        @if($organization->phone)
        <div class="small">Tel: {{ $organization->phone }}</div>
        @endif
        @if($organization->tax_number)
        <div class="small bold">Tax: {{ $organization->tax_number }}</div>
        @endif
    </div>

    <div class="sep"></div>

    <div class="center large bold">PAYMENT RECEIPT</div>

    <div class="sep"></div>

    <div class="row">
        <div class="col-left">Receipt No:</div>
        <div class="col-right bold">{{ $payment->payment_number }}</div>
    </div>
    <div class="row">
        <div class="col-left">Date:</div>
        <div class="col-right">{{ $payment->payment_date->format('d/m/Y H:i') }}</div>
    </div>
    <div class="row">
        <div class="col-left">Payment:</div>
        <div class="col-right">{{ ucfirst($payment->payment_method ?? 'Cash') }}</div>
    </div>

    <div class="sep-bold"></div>

    @if($customer ?? null)
    <div>
        <div class="small" style="color: #666;">Received From:</div>
        <div class="bold">{{ Str::limit($customer->company_name ?? 'Customer', 30) }}</div>
    </div>
    <div class="sep"></div>
    @endif

    <div class="amount-box">
        <div class="amount-label">AMOUNT RECEIVED</div>
        <div class="amount-value">{{ $payment->currency_code }} {{ number_format((float)$payment->amount, 2) }}</div>
    </div>

    @if($allocations && $allocations->count() > 0)
    <div class="sep"></div>
    <div class="small bold">Applied to:</div>
    @foreach($allocations as $allocation)
    <div class="invoice-row row">
        <div class="col-left">{{ $allocation->invoice->invoice_number ?? 'Invoice' }}</div>
        <div class="col-right">{{ number_format((float)$allocation->amount, 2) }}</div>
    </div>
    @endforeach
    @endif

    @if($payment->reference)
    <div class="sep"></div>
    <div class="row small">
        <div class="col-left">Reference:</div>
        <div class="col-right">{{ $payment->reference }}</div>
    </div>
    @endif

    <div class="sep"></div>

    <div class="center footer">
        <div class="bold">Thank you!</div>
        <div class="sep"></div>
        <div>{{ $organization->name }}</div>
        <div class="small">{{ now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <div style="height: 12mm;"></div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Delivery Note {{ $deliveryNote->delivery_number ?? $deliveryNote->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 15mm; }

        .header { display: table; width: 100%; margin-bottom: 20px; }
        .header-left, .header-right { display: table-cell; width: 50%; vertical-align: top; }
        .header-right { text-align: right; }

        .logo { max-height: 50px; margin-bottom: 8px; }
        .company-name { font-size: 16px; font-weight: bold; color: {{ $primaryColor }}; }
        .company-details { color: #666; font-size: 10px; }

        .doc-title { font-size: 24px; font-weight: bold; color: {{ $primaryColor }}; }
        .doc-number { font-size: 12px; color: #666; }

        .divider { border-top: 2px solid {{ $secondaryColor }}; margin: 15px 0; }

        .info-section { display: table; width: 100%; margin-bottom: 20px; }
        .info-box { display: table-cell; width: 33%; vertical-align: top; padding-right: 15px; }
        .info-box-title { font-weight: bold; font-size: 10px; text-transform: uppercase; color: #666; margin-bottom: 5px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .info-content { font-size: 10px; padding: 5px 0; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th {
            background: {{ $primaryColor }};
            color: #fff;
            padding: 8px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }
        .items-table td { padding: 8px; border-bottom: 1px solid #eee; font-size: 10px; }
        .items-table tr:nth-child(even) { background: #f9f9f9; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }

        .summary-box {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .summary-row { display: table; width: 100%; margin: 5px 0; }
        .summary-label { display: table-cell; width: 70%; font-size: 11px; }
        .summary-value { display: table-cell; width: 30%; text-align: right; font-weight: bold; font-size: 11px; }

        .signature-section { display: table; width: 100%; margin-top: 40px; }
        .signature-box { display: table-cell; width: 33%; text-align: center; }
        .signature-line { border-top: 1px solid #333; width: 120px; margin: 0 auto; padding-top: 5px; }
        .signature-label { font-size: 9px; color: #666; }

        .notes { margin-top: 20px; font-size: 10px; color: #666; }
        .notes-title { font-weight: bold; margin-bottom: 5px; }

        .footer { margin-top: 30px; text-align: center; font-size: 9px; color: #666; border-top: 1px solid #ddd; padding-top: 10px; }

        .checkbox { display: inline-block; width: 12px; height: 12px; border: 1px solid #333; margin-right: 5px; vertical-align: middle; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                @if($showLogo && $organization->logo_url)
                <img src="{{ $organization->logo_url }}" class="logo">
                @endif
                <div class="company-name">{{ $organization->legal_name ?? $organization->name }}</div>
                <div class="company-details">
                    @if($organization->address_line_1){{ $organization->address_line_1 }}<br>@endif
                    {{ $organization->city ?? '' }} {{ $organization->postal_code ?? '' }}<br>
                    @if($organization->phone)Tel: {{ $organization->phone }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="doc-title">DELIVERY NOTE</div>
                <div class="doc-number"># {{ $deliveryNote->delivery_number ?? $deliveryNote->id }}</div>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Deliver To</div>
                <div class="info-content">
                    <strong>{{ $deliveryNote->customer_name ?? $customer->company_name ?? 'N/A' }}</strong><br>
                    @if($deliveryNote->shipping_address ?? $customer->shipping_address ?? null)
                        {!! nl2br(e($deliveryNote->shipping_address ?? $customer->shipping_address)) !!}<br>
                    @endif
                    @if($deliveryNote->contact_phone ?? $customer->phone ?? null)
                        Tel: {{ $deliveryNote->contact_phone ?? $customer->phone }}
                    @endif
                </div>
            </div>
            <div class="info-box">
                <div class="info-box-title">Delivery Details</div>
                <div class="info-content">
                    <strong>Date:</strong> {{ ($deliveryNote->delivery_date ?? now())->format('d M Y') }}<br>
                    @if($deliveryNote->invoice_number ?? null)
                    <strong>Invoice:</strong> {{ $deliveryNote->invoice_number }}<br>
                    @endif
                    @if($deliveryNote->so_number ?? null)
                    <strong>Sales Order:</strong> {{ $deliveryNote->so_number }}<br>
                    @endif
                    @if($deliveryNote->po_number ?? null)
                    <strong>PO Number:</strong> {{ $deliveryNote->po_number }}
                    @endif
                </div>
            </div>
            <div class="info-box">
                <div class="info-box-title">Ship From</div>
                <div class="info-content">
                    <strong>{{ $deliveryNote->warehouse_name ?? $organization->name }}</strong><br>
                    @if($deliveryNote->warehouse_address ?? $organization->address_line_1 ?? null)
                        {{ $deliveryNote->warehouse_address ?? $organization->address_line_1 }}<br>
                    @endif
                    {{ $organization->city ?? '' }}
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 15%;">SKU</th>
                    <th style="width: 40%;">Description</th>
                    <th class="text-center" style="width: 15%;">Qty Ordered</th>
                    <th class="text-center" style="width: 15%;">Qty Delivered</th>
                    <th class="text-center" style="width: 10%;">Received</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line->product?->sku ?? '-' }}</td>
                    <td>
                        {{ $line->description }}
                        @if($line->batch_number ?? null)
                        <br><small style="color: #666;">Batch: {{ $line->batch_number }}</small>
                        @endif
                    </td>
                    <td class="text-center">{{ number_format((float)($line->ordered_quantity ?? $line->quantity), 2) }}</td>
                    <td class="text-center">{{ number_format((float)$line->quantity, 2) }}</td>
                    <td class="text-center"><span class="checkbox"></span></td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Summary -->
        <div class="summary-box">
            <div class="summary-row">
                <span class="summary-label">Total Items:</span>
                <span class="summary-value">{{ $lines->count() }}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Total Quantity:</span>
                <span class="summary-value">{{ number_format($lines->sum('quantity'), 2) }}</span>
            </div>
            @if($deliveryNote->total_packages ?? null)
            <div class="summary-row">
                <span class="summary-label">Total Packages:</span>
                <span class="summary-value">{{ $deliveryNote->total_packages }}</span>
            </div>
            @endif
            @if($deliveryNote->total_weight ?? null)
            <div class="summary-row">
                <span class="summary-label">Total Weight:</span>
                <span class="summary-value">{{ number_format($deliveryNote->total_weight, 2) }} kg</span>
            </div>
            @endif
        </div>

        <!-- Notes -->
        @if($deliveryNote->notes ?? null)
        <div class="notes">
            <div class="notes-title">Delivery Notes:</div>
            {!! nl2br(e($deliveryNote->notes)) !!}
        </div>
        @endif

        <!-- Signatures -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">Prepared By</div>
                <div class="signature-label">Name / Date</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Delivered By</div>
                <div class="signature-label">Name / Date / Vehicle</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Received By</div>
                <div class="signature-label">Name / Date / Stamp</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            {{ $organization->name }} | Generated: {{ now()->format('d M Y H:i') }}
        </div>
    </div>
</body>
</html>

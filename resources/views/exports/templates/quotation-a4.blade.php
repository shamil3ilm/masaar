<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Quotation {{ $quotation->quotation_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 15mm; }

        .header { display: table; width: 100%; margin-bottom: 25px; }
        .header-left, .header-right { display: table-cell; width: 50%; vertical-align: top; }
        .header-right { text-align: right; }

        .logo { max-height: 60px; margin-bottom: 10px; }
        .company-name { font-size: 18px; font-weight: bold; color: {{ $primaryColor }}; }
        .company-details { color: #666; font-size: 10px; line-height: 1.5; }

        .doc-title { font-size: 28px; font-weight: bold; color: {{ $primaryColor }}; }
        .doc-number { font-size: 14px; color: #666; margin-top: 5px; }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
            margin-top: 10px;
        }
        .status-draft { background: #95a5a6; color: #fff; }
        .status-sent { background: #3498db; color: #fff; }
        .status-accepted { background: #27ae60; color: #fff; }
        .status-declined { background: #e74c3c; color: #fff; }
        .status-expired { background: #f39c12; color: #fff; }

        .divider { border-top: 2px solid {{ $secondaryColor }}; margin: 20px 0; }

        .info-section { display: table; width: 100%; margin-bottom: 25px; }
        .info-box { display: table-cell; width: 50%; vertical-align: top; padding-right: 20px; }
        .info-box:last-child { padding-right: 0; padding-left: 20px; }
        .info-box-title {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 8px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        .info-content { padding: 5px 0; font-size: 11px; }

        .details-table { margin-left: auto; }
        .details-table td { padding: 4px 10px; font-size: 11px; }
        .details-table td:first-child { color: #666; text-align: right; }
        .details-table td:last-child { font-weight: bold; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .items-table th {
            background: {{ $primaryColor }};
            color: #fff;
            padding: 10px 8px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }
        .items-table th.text-right, .items-table td.text-right { text-align: right; }
        .items-table th.text-center, .items-table td.text-center { text-align: center; }
        .items-table td { padding: 10px 8px; border-bottom: 1px solid #eee; font-size: 11px; }
        .items-table tr:nth-child(even) { background: #f9f9f9; }
        .item-name { font-weight: 500; }
        .item-sku { font-size: 9px; color: #999; }

        .totals-section { display: table; width: 100%; }
        .notes-section { display: table-cell; width: 55%; vertical-align: top; padding-right: 30px; }
        .totals-container { display: table-cell; width: 45%; vertical-align: top; }

        .totals-table { width: 100%; }
        .totals-table td { padding: 6px 10px; font-size: 11px; }
        .totals-table td:first-child { text-align: right; color: #666; }
        .totals-table td:last-child { text-align: right; font-weight: bold; width: 120px; }

        .total-row { background: {{ $primaryColor }}; color: #fff; }
        .total-row td { padding: 12px 10px !important; font-size: 14px; }

        .notes-title { font-weight: bold; font-size: 10px; text-transform: uppercase; color: #666; margin-bottom: 5px; }
        .notes-content { font-size: 10px; color: #666; padding: 10px; background: #f9f9f9; border-radius: 4px; }

        .validity-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 10px;
            margin: 20px 0;
            text-align: center;
        }
        .validity-text { font-size: 11px; color: #856404; }
        .validity-date { font-weight: bold; color: #856404; }

        .cta-box {
            background: linear-gradient(135deg, {{ $primaryColor }} 0%, {{ $secondaryColor }} 100%);
            color: #fff;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            margin: 25px 0;
        }
        .cta-text { font-size: 12px; margin-bottom: 5px; }
        .cta-contact { font-size: 14px; font-weight: bold; }

        .signature-section { display: table; width: 100%; margin-top: 40px; }
        .signature-box { display: table-cell; width: 50%; text-align: center; }
        .signature-line { border-top: 1px solid #333; width: 150px; margin: 0 auto; padding-top: 5px; }
        .signature-label { font-size: 10px; color: #666; }

        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 9px; border-top: 1px solid #ddd; padding-top: 15px; }

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
                <div class="company-name">{{ $organization->legal_name ?? $organization->name }}</div>
                <div class="company-details">
                    @if($organization->address_line_1){{ $organization->address_line_1 }}<br>@endif
                    {{ $organization->city ?? '' }}@if($organization->city && $organization->state), @endif{{ $organization->state ?? '' }} {{ $organization->postal_code ?? '' }}<br>
                    @if($organization->tax_number)Tax No: {{ $organization->tax_number }}<br>@endif
                    @if($organization->phone)Tel: {{ $organization->phone }}<br>@endif
                    @if($organization->email){{ $organization->email }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="doc-title">QUOTATION</div>
                <div class="doc-number"># {{ $quotation->quotation_number }}</div>
                <span class="status-badge status-{{ $quotation->status }}">{{ strtoupper($quotation->status) }}</span>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Prepared For</div>
                <div class="info-content">
                    <strong>{{ $quotation->customer_name ?? $customer->company_name ?? 'N/A' }}</strong><br>
                    @if($quotation->billing_address ?? $customer->billing_address ?? null)
                        {!! nl2br(e($quotation->billing_address ?? $customer->billing_address)) !!}<br>
                    @endif
                    @if($quotation->customer_tax_number ?? $customer->tax_number ?? null)
                        Tax No: {{ $quotation->customer_tax_number ?? $customer->tax_number }}<br>
                    @endif
                    @if($quotation->customer_email ?? $customer->email ?? null)
                        {{ $quotation->customer_email ?? $customer->email }}
                    @endif
                </div>
            </div>
            <div class="info-box" style="text-align: right;">
                <table class="details-table">
                    <tr>
                        <td>Quote Date:</td>
                        <td>{{ $quotation->quotation_date->format('d M Y') }}</td>
                    </tr>
                    @if($quotation->valid_until)
                    <tr>
                        <td>Valid Until:</td>
                        <td style="color: {{ $quotation->valid_until->isPast() ? '#e74c3c' : 'inherit' }};">
                            {{ $quotation->valid_until->format('d M Y') }}
                        </td>
                    </tr>
                    @endif
                    @if($quotation->reference)
                    <tr>
                        <td>Reference:</td>
                        <td>{{ $quotation->reference }}</td>
                    </tr>
                    @endif
                    @if($quotation->salesperson)
                    <tr>
                        <td>Sales Rep:</td>
                        <td>{{ $quotation->salesperson->name }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>Currency:</td>
                        <td>{{ $quotation->currency_code }}</td>
                    </tr>
                </table>
            </div>
        </div>

        @if($quotation->subject)
        <div style="margin-bottom: 20px; padding: 10px; background: #f0f4f8; border-left: 4px solid {{ $primaryColor }};">
            <strong style="font-size: 11px; color: #666;">Subject:</strong><br>
            <span style="font-size: 12px;">{{ $quotation->subject }}</span>
        </div>
        @endif

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%">#</th>
                    <th style="width: 40%">Description</th>
                    <th class="text-center" style="width: 10%">Qty</th>
                    <th class="text-right" style="width: 15%">Unit Price</th>
                    @if($lines->where('discount_amount', '>', 0)->count() > 0)
                    <th class="text-right" style="width: 10%">Discount</th>
                    @endif
                    <th class="text-right" style="width: 10%">Tax</th>
                    <th class="text-right" style="width: 15%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>
                        <div class="item-name">{{ $line->description }}</div>
                        @if($line->product?->sku)
                        <div class="item-sku">SKU: {{ $line->product->sku }}</div>
                        @endif
                        @if($line->notes)
                        <div style="font-size: 9px; color: #666; margin-top: 3px;">{{ $line->notes }}</div>
                        @endif
                    </td>
                    <td class="text-center">{{ number_format((float)$line->quantity, 2) }} {{ $line->unit?->symbol ?? '' }}</td>
                    <td class="text-right">{{ number_format((float)$line->unit_price, 2) }}</td>
                    @if($lines->where('discount_amount', '>', 0)->count() > 0)
                    <td class="text-right">
                        @if((float)$line->discount_amount > 0)
                            -{{ number_format((float)$line->discount_amount, 2) }}
                        @else
                            -
                        @endif
                    </td>
                    @endif
                    <td class="text-right">{{ $line->tax_rate ?? 0 }}%</td>
                    <td class="text-right">{{ number_format((float)$line->total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals & Notes -->
        <div class="totals-section">
            <div class="notes-section">
                @if($quotation->notes)
                <div class="notes-title">Notes</div>
                <div class="notes-content">{!! nl2br(e($quotation->notes)) !!}</div>
                @endif

                @if($quotation->terms_and_conditions)
                <div class="notes-title" style="margin-top: 15px;">Terms & Conditions</div>
                <div class="notes-content">{!! nl2br(e($quotation->terms_and_conditions)) !!}</div>
                @endif
            </div>
            <div class="totals-container">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal:</td>
                        <td>{{ number_format((float)$quotation->subtotal, 2) }}</td>
                    </tr>
                    @if((float)$quotation->discount_amount > 0)
                    <tr>
                        <td>Discount{{ $quotation->discount_type === 'percentage' ? " ({$quotation->discount_value}%)" : '' }}:</td>
                        <td>-{{ number_format((float)$quotation->discount_amount, 2) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>Tax:</td>
                        <td>{{ number_format((float)$quotation->tax_amount, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Total:</td>
                        <td>{{ $quotation->currency_code }} {{ number_format((float)$quotation->total, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Validity Notice -->
        @if($quotation->valid_until)
        <div class="validity-box">
            <div class="validity-text">
                This quotation is valid until
                <span class="validity-date">{{ $quotation->valid_until->format('d M Y') }}</span>
                @if($quotation->valid_until->isPast())
                <br><strong style="color: #e74c3c;">(EXPIRED)</strong>
                @endif
            </div>
        </div>
        @endif

        <!-- Call to Action -->
        <div class="cta-box">
            <div class="cta-text">Questions? Ready to proceed? Contact us:</div>
            <div class="cta-contact">
                @if($quotation->salesperson)
                    {{ $quotation->salesperson->name }} |
                    {{ $quotation->salesperson->email ?? $organization->email }}
                    @if($quotation->salesperson->phone) | {{ $quotation->salesperson->phone }} @endif
                @else
                    {{ $organization->email }}
                    @if($organization->phone) | {{ $organization->phone }} @endif
                @endif
            </div>
        </div>

        <!-- Acceptance Section -->
        @if($showSignature)
        <div style="border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-top: 20px;">
            <div style="font-weight: bold; margin-bottom: 10px;">Acceptance</div>
            <p style="font-size: 10px; color: #666; margin-bottom: 15px;">
                By signing below, you accept this quotation and agree to the terms and conditions stated above.
            </p>
            <div class="signature-section" style="margin-top: 20px;">
                <div class="signature-box">
                    <div class="signature-line">Customer Signature</div>
                    <div class="signature-label">Name / Date</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line">Official Stamp</div>
                    <div class="signature-label">(if applicable)</div>
                </div>
            </div>
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            {{ $organization->legal_name ?? $organization->name }}
            @if($organization->website) | {{ $organization->website }} @endif
            <br>Generated: {{ now()->format('d M Y H:i') }}
        </div>
    </div>
</body>
</html>

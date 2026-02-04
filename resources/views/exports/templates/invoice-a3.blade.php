<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @page { size: A3 portrait; margin: 20mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #333;
        }

        .container { padding: 15mm; }

        /* Header - Large */
        .header { display: table; width: 100%; margin-bottom: 40px; }
        .header-left, .header-right { display: table-cell; width: 50%; vertical-align: top; }
        .header-right { text-align: right; }

        .logo { max-height: 100px; max-width: 300px; margin-bottom: 15px; }
        .company-name { font-size: 28px; font-weight: bold; color: {{ $primaryColor }}; margin-bottom: 10px; }
        .company-details { color: #666; font-size: 14px; line-height: 1.6; }

        .invoice-title { font-size: 48px; font-weight: bold; color: {{ $primaryColor }}; }
        .invoice-number { font-size: 20px; color: #666; margin-top: 10px; }

        .status-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 5px;
            font-size: 14px;
            text-transform: uppercase;
            font-weight: bold;
            margin-top: 15px;
        }
        .status-paid { background: #27ae60; color: #fff; }
        .status-sent { background: #3498db; color: #fff; }
        .status-overdue { background: #e74c3c; color: #fff; }
        .status-draft { background: #95a5a6; color: #fff; }
        .status-partial { background: #f39c12; color: #fff; }

        .divider { border-top: 3px solid {{ $secondaryColor }}; margin: 30px 0; }

        /* Info Section - Large */
        .info-section { display: table; width: 100%; margin-bottom: 40px; }
        .info-box { display: table-cell; width: 50%; vertical-align: top; padding-right: 30px; }
        .info-box:last-child { padding-right: 0; padding-left: 30px; }
        .info-box-title {
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 12px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 8px;
        }
        .info-content { padding: 8px 0; font-size: 14px; }
        .info-content strong { font-size: 16px; }

        /* Invoice Details */
        .invoice-details { text-align: right; }
        .invoice-details table { margin-left: auto; }
        .invoice-details td { padding: 6px 15px; font-size: 14px; }
        .invoice-details td:first-child { color: #666; text-align: right; }
        .invoice-details td:last-child { font-weight: bold; }

        /* Items Table - Large */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
        .items-table th {
            background: {{ $primaryColor }};
            color: #fff;
            padding: 15px 12px;
            text-align: left;
            font-size: 14px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .items-table th.text-right, .items-table td.text-right { text-align: right; }
        .items-table th.text-center, .items-table td.text-center { text-align: center; }
        .items-table td { padding: 15px 12px; border-bottom: 1px solid #ddd; font-size: 14px; }
        .items-table tr:nth-child(even) { background: #f9f9f9; }
        .items-table .item-name { font-weight: 500; font-size: 15px; }
        .items-table .item-sku { font-size: 11px; color: #999; margin-top: 3px; }
        .items-table .item-desc { font-size: 12px; color: #666; margin-top: 5px; }

        /* GST Details */
        .gst-details { font-size: 11px; color: #666; margin-top: 3px; }

        /* Totals - Large */
        .totals-section { display: table; width: 100%; page-break-inside: avoid; }
        .notes-section { display: table-cell; width: 55%; vertical-align: top; padding-right: 50px; }
        .totals-container { display: table-cell; width: 45%; vertical-align: top; }

        .totals-table { width: 100%; }
        .totals-table td { padding: 10px 15px; font-size: 16px; }
        .totals-table td:first-child { text-align: right; color: #666; }
        .totals-table td:last-child { text-align: right; font-weight: bold; width: 180px; }

        .total-row { background: {{ $primaryColor }}; color: #fff; }
        .total-row td { padding: 18px 15px !important; font-size: 22px; }
        .balance-due { background: #e74c3c; }
        .subtotal-row { border-top: 2px solid #ddd; }

        /* Notes - Large */
        .notes-title {
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 8px;
        }
        .notes-content {
            font-size: 13px;
            color: #666;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 5px;
            line-height: 1.6;
        }

        /* QR Code - Large */
        .qr-section { text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #ddd; }
        .qr-code img { max-width: 150px; }
        .qr-label { font-size: 12px; color: #666; margin-top: 10px; }

        /* Signature */
        .signature-section { margin-top: 60px; display: table; width: 100%; }
        .signature-box { display: table-cell; width: 33%; text-align: center; }
        .signature-line { border-top: 2px solid #333; width: 200px; margin: 0 auto; padding-top: 10px; }
        .signature-label { font-size: 14px; color: #666; }

        /* Footer */
        .footer {
            margin-top: 50px;
            text-align: center;
            color: #666;
            font-size: 13px;
            border-top: 2px solid #ddd;
            padding-top: 20px;
        }

        /* Bank Details Box */
        .bank-details {
            background: #f0f4f8;
            border: 1px solid #d0d9e3;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
        }
        .bank-details-title {
            font-weight: bold;
            font-size: 14px;
            color: {{ $primaryColor }};
            margin-bottom: 10px;
        }
        .bank-details-content { font-size: 13px; line-height: 1.6; }

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
                    @if($organization->address_line_2){{ $organization->address_line_2 }}<br>@endif
                    {{ $organization->city ?? '' }}@if($organization->city && $organization->state), @endif{{ $organization->state ?? '' }} {{ $organization->postal_code ?? '' }}<br>
                    @if($organization->country_code){{ config("regional.{$organization->country_code}.name", $organization->country_code) }}<br>@endif
                    @if($organization->tax_number)
                        @if($organization->country_code === 'IN')GSTIN: @else VAT/TRN: @endif{{ $organization->tax_number }}<br>
                    @endif
                    @if($organization->phone)Tel: {{ $organization->phone }}<br>@endif
                    @if($organization->email){{ $organization->email }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="invoice-title">
                    @if($invoice->invoice_type === 'credit_note')CREDIT NOTE
                    @elseif($invoice->invoice_type === 'simplified')SIMPLIFIED TAX INVOICE
                    @else TAX INVOICE
                    @endif
                </div>
                <div class="invoice-number"># {{ $invoice->invoice_number }}</div>
                <span class="status-badge status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Bill To & Invoice Details -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Bill To</div>
                <div class="info-content">
                    <strong>{{ $invoice->customer_name }}</strong><br>
                    @if($invoice->billing_address){!! nl2br(e($invoice->billing_address)) !!}<br>@endif
                    @if($invoice->customer_tax_number)
                        @if($organization->country_code === 'IN')GSTIN: @else VAT/TRN: @endif{{ $invoice->customer_tax_number }}<br>
                    @endif
                    @if($invoice->customer_email){{ $invoice->customer_email }}@endif
                </div>
            </div>
            <div class="info-box">
                @if($invoice->shipping_address)
                <div class="info-box-title">Ship To</div>
                <div class="info-content">
                    {!! nl2br(e($invoice->shipping_address)) !!}
                </div>
                @endif
            </div>
            <div class="info-box invoice-details">
                <table>
                    <tr><td>Invoice Date:</td><td>{{ $invoice->invoice_date->format('d M Y') }}</td></tr>
                    @if($invoice->due_date)<tr><td>Due Date:</td><td>{{ $invoice->due_date->format('d M Y') }}</td></tr>@endif
                    @if($invoice->reference)<tr><td>Reference:</td><td>{{ $invoice->reference }}</td></tr>@endif
                    @if($invoice->po_number)<tr><td>PO Number:</td><td>{{ $invoice->po_number }}</td></tr>@endif
                    @if($invoice->place_of_supply)<tr><td>Place of Supply:</td><td>{{ $invoice->place_of_supply }}</td></tr>@endif
                    <tr><td>Currency:</td><td>{{ $invoice->currency_code }}</td></tr>
                    @if($invoice->salesperson)<tr><td>Sales Person:</td><td>{{ $invoice->salesperson->name }}</td></tr>@endif
                </table>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 4%">#</th>
                    <th style="width: 8%">SKU</th>
                    <th style="width: 28%">Description</th>
                    @if($organization->country_code === 'IN')<th style="width: 8%">HSN/SAC</th>@endif
                    <th class="text-center" style="width: 8%">Qty</th>
                    <th class="text-right" style="width: 10%">Unit Price</th>
                    @if($invoice->lines->where('discount_amount', '>', 0)->count() > 0)
                    <th class="text-right" style="width: 8%">Discount</th>
                    @endif
                    <th class="text-right" style="width: 10%">Taxable</th>
                    <th class="text-right" style="width: 8%">Tax</th>
                    <th class="text-right" style="width: 10%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line->product?->sku ?? '-' }}</td>
                    <td>
                        <div class="item-name">{{ $line->description }}</div>
                        @if($line->notes)<div class="item-desc">{{ $line->notes }}</div>@endif
                    </td>
                    @if($organization->country_code === 'IN')<td>{{ $line->hsn_code ?? '-' }}</td>@endif
                    <td class="text-center">{{ number_format((float)$line->quantity, 3) }} {{ $line->unit?->symbol ?? '' }}</td>
                    <td class="text-right">{{ number_format((float)$line->unit_price, 2) }}</td>
                    @if($invoice->lines->where('discount_amount', '>', 0)->count() > 0)
                    <td class="text-right">
                        @if((float)$line->discount_amount > 0)-{{ number_format((float)$line->discount_amount, 2) }}@else - @endif
                    </td>
                    @endif
                    <td class="text-right">{{ number_format((float)$line->subtotal, 2) }}</td>
                    <td class="text-right">
                        @if($organization->country_code === 'IN' && $line->igst_amount)
                            IGST {{ $line->igst_rate }}%
                            <div class="gst-details">{{ number_format((float)$line->igst_amount, 2) }}</div>
                        @elseif($organization->country_code === 'IN' && $line->cgst_amount)
                            CGST {{ $line->cgst_rate }}%<br>SGST {{ $line->sgst_rate }}%
                            <div class="gst-details">{{ number_format((float)$line->cgst_amount + (float)$line->sgst_amount, 2) }}</div>
                        @else
                            {{ $line->tax_rate ?? 0 }}%
                            <div class="gst-details">{{ number_format((float)$line->tax_amount, 2) }}</div>
                        @endif
                    </td>
                    <td class="text-right">{{ number_format((float)$line->total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals & Notes -->
        <div class="totals-section">
            <div class="notes-section">
                @if($invoice->notes)
                <div class="notes-title">Notes</div>
                <div class="notes-content">{!! nl2br(e($invoice->notes)) !!}</div>
                @endif

                @if($invoice->terms_and_conditions)
                <div class="notes-title" style="margin-top: 20px;">Terms & Conditions</div>
                <div class="notes-content">{!! nl2br(e($invoice->terms_and_conditions)) !!}</div>
                @endif

                @if($organization->bank_name)
                <div class="bank-details">
                    <div class="bank-details-title">Bank Details</div>
                    <div class="bank-details-content">
                        Bank: {{ $organization->bank_name }}<br>
                        @if($organization->bank_account_name)Account Name: {{ $organization->bank_account_name }}<br>@endif
                        @if($organization->bank_account_number)Account No: {{ $organization->bank_account_number }}<br>@endif
                        @if($organization->bank_iban)IBAN: {{ $organization->bank_iban }}<br>@endif
                        @if($organization->bank_swift)SWIFT: {{ $organization->bank_swift }}@endif
                    </div>
                </div>
                @endif
            </div>
            <div class="totals-container">
                <table class="totals-table">
                    <tr><td>Subtotal:</td><td>{{ number_format((float)$invoice->subtotal, 2) }}</td></tr>
                    @if((float)$invoice->discount_amount > 0)
                    <tr><td>Discount:</td><td>-{{ number_format((float)$invoice->discount_amount, 2) }}</td></tr>
                    @endif
                    @if($organization->country_code === 'IN')
                        @php
                            $cgstTotal = $lines->sum('cgst_amount');
                            $sgstTotal = $lines->sum('sgst_amount');
                            $igstTotal = $lines->sum('igst_amount');
                        @endphp
                        @if($igstTotal > 0)
                        <tr><td>IGST:</td><td>{{ number_format((float)$igstTotal, 2) }}</td></tr>
                        @else
                        <tr><td>CGST:</td><td>{{ number_format((float)$cgstTotal, 2) }}</td></tr>
                        <tr><td>SGST:</td><td>{{ number_format((float)$sgstTotal, 2) }}</td></tr>
                        @endif
                    @else
                    <tr><td>VAT:</td><td>{{ number_format((float)$invoice->tax_amount, 2) }}</td></tr>
                    @endif
                    <tr class="total-row"><td>Total:</td><td>{{ $invoice->currency_code }} {{ number_format((float)$invoice->total, 2) }}</td></tr>
                    @if((float)$invoice->amount_paid > 0)
                    <tr class="subtotal-row"><td>Amount Paid:</td><td>{{ number_format((float)$invoice->amount_paid, 2) }}</td></tr>
                    @endif
                    @if((float)$invoice->amount_due > 0 && $invoice->status !== 'paid')
                    <tr class="total-row balance-due"><td>Balance Due:</td><td>{{ $invoice->currency_code }} {{ number_format((float)$invoice->amount_due, 2) }}</td></tr>
                    @endif
                </table>
            </div>
        </div>

        <!-- QR Code -->
        @if($showQrCode && $invoice->compliance_qr_code)
        <div class="qr-section">
            <img src="data:image/png;base64,{{ $invoice->compliance_qr_code }}" alt="QR Code" class="qr-code">
            @if($invoice->compliance_uuid)<div class="qr-label">UUID: {{ $invoice->compliance_uuid }}</div>@endif
        </div>
        @endif

        <!-- Signature -->
        @if($showSignature)
        <div class="signature-section">
            <div class="signature-box"><div class="signature-line">Prepared By</div></div>
            <div class="signature-box"><div class="signature-line">Authorized Signature</div></div>
            <div class="signature-box"><div class="signature-line">Customer Signature</div></div>
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

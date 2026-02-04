<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Quotation {{ $quotation->quotation_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #333; }
        .container { padding: 20px; }
        .header { display: table; width: 100%; margin-bottom: 30px; }
        .header-left, .header-right { display: table-cell; width: 50%; vertical-align: top; }
        .company-name { font-size: 20px; font-weight: bold; color: #27ae60; margin-bottom: 5px; }
        .company-details { color: #666; font-size: 11px; }
        .quote-title { text-align: right; font-size: 28px; font-weight: bold; color: #27ae60; }
        .quote-number { text-align: right; font-size: 14px; color: #666; }
        .divider { border-top: 2px solid #27ae60; margin: 20px 0; }
        .info-section { display: table; width: 100%; margin-bottom: 20px; }
        .info-box { display: table-cell; width: 50%; vertical-align: top; padding-right: 20px; }
        .info-box-title { font-weight: bold; font-size: 11px; text-transform: uppercase; color: #666; margin-bottom: 5px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .info-content { padding: 5px 0; }
        .quote-details { text-align: right; }
        .quote-details table { margin-left: auto; }
        .quote-details td { padding: 3px 10px; }
        .quote-details td:first-child { color: #666; text-align: right; }
        .quote-details td:last-child { font-weight: bold; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th { background: #27ae60; color: #fff; padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase; }
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
        .total-row { background: #27ae60; color: #fff; }
        .total-row td { padding: 10px !important; font-size: 14px; }
        .notes-title { font-weight: bold; font-size: 11px; text-transform: uppercase; color: #666; margin-bottom: 5px; }
        .notes-content { font-size: 10px; color: #666; padding: 10px; background: #f9f9f9; border-radius: 3px; }
        .validity-box { margin-top: 20px; padding: 15px; background: #e8f6e8; border-left: 4px solid #27ae60; }
        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 10px; border-top: 1px solid #ddd; padding-top: 15px; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 10px; text-transform: uppercase; font-weight: bold; }
        .status-draft { background: #95a5a6; color: #fff; }
        .status-sent { background: #3498db; color: #fff; }
        .status-accepted { background: #27ae60; color: #fff; }
        .status-rejected { background: #e74c3c; color: #fff; }
        .status-expired { background: #e67e22; color: #fff; }
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
                <div class="quote-title">QUOTATION</div>
                <div class="quote-number"># {{ $quotation->quotation_number }}</div>
                <div style="text-align: right; margin-top: 10px;">
                    <span class="status-badge status-{{ $quotation->status }}">{{ strtoupper($quotation->status) }}</span>
                </div>
            </div>
        </div>

        <div class="divider"></div>

        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Quote To</div>
                <div class="info-content">
                    <strong>{{ $quotation->customer_name }}</strong><br>
                    @if($quotation->billing_address)
                        {!! nl2br(e($quotation->billing_address)) !!}<br>
                    @endif
                    @if($quotation->customer_email)
                        {{ $quotation->customer_email }}
                    @endif
                </div>
            </div>
            <div class="info-box quote-details">
                <table>
                    <tr>
                        <td>Quote Date:</td>
                        <td>{{ $quotation->quotation_date->format('M d, Y') }}</td>
                    </tr>
                    @if($quotation->valid_until)
                    <tr>
                        <td>Valid Until:</td>
                        <td>{{ $quotation->valid_until->format('M d, Y') }}</td>
                    </tr>
                    @endif
                    @if($quotation->reference)
                    <tr>
                        <td>Reference:</td>
                        <td>{{ $quotation->reference }}</td>
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
        <div style="margin-bottom: 20px;">
            <strong>Subject:</strong> {{ $quotation->subject }}
        </div>
        @endif

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 40%">Description</th>
                    <th style="width: 10%">Qty</th>
                    <th style="width: 15%">Unit Price</th>
                    <th style="width: 10%">Tax</th>
                    <th style="width: 15%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ number_format($line->quantity, 2) }}</td>
                    <td>{{ number_format($line->unit_price, 2) }}</td>
                    <td>{{ $line->tax_rate }}%</td>
                    <td>{{ number_format($line->total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-section">
            <div class="notes-section">
                @if($quotation->notes)
                <div class="notes-title">Notes</div>
                <div class="notes-content">
                    {!! nl2br(e($quotation->notes)) !!}
                </div>
                @endif
            </div>
            <div class="totals-table-container">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal:</td>
                        <td>{{ number_format($quotation->subtotal, 2) }}</td>
                    </tr>
                    @if($quotation->discount_amount > 0)
                    <tr>
                        <td>Discount:</td>
                        <td>-{{ number_format($quotation->discount_amount, 2) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>Tax:</td>
                        <td>{{ number_format($quotation->tax_amount, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Total:</td>
                        <td>{{ $quotation->currency_code }} {{ number_format($quotation->total, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        @if($quotation->terms_and_conditions)
        <div class="validity-box">
            <div class="notes-title">Terms & Conditions</div>
            <div style="font-size: 10px; color: #333;">
                {!! nl2br(e($quotation->terms_and_conditions)) !!}
            </div>
        </div>
        @endif

        <div class="footer">
            This quotation is valid for {{ $quotation->valid_until ? $quotation->quotation_date->diffInDays($quotation->valid_until) : 30 }} days from the date of issue.<br>
            {{ $organization->legal_name ?? $organization->name }}
            @if($organization->website) | {{ $organization->website }} @endif
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Balance Sheet</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }
        .container { padding: 10mm; }

        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #1e40af; padding-bottom: 15px; }
        .company-name { font-size: 18px; font-weight: bold; color: #1e40af; }
        .report-title { font-size: 16px; font-weight: bold; color: #333; margin-top: 5px; }
        .report-date { font-size: 11px; color: #666; margin-top: 5px; }

        .section { margin-bottom: 15px; }
        .section-header {
            background: #1e40af;
            color: #fff;
            padding: 8px 10px;
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
        }

        .sub-section { margin-left: 15px; margin-top: 5px; }
        .sub-header { font-weight: bold; font-size: 10px; color: #374151; padding: 5px 0; border-bottom: 1px dashed #ddd; }

        .account-row { display: table; width: 100%; padding: 4px 0; }
        .account-name { display: table-cell; width: 70%; padding-left: 20px; }
        .account-balance { display: table-cell; width: 30%; text-align: right; }

        .subtotal-row { display: table; width: 100%; padding: 6px 0; font-weight: bold; border-top: 1px solid #ddd; }
        .subtotal-label { display: table-cell; width: 70%; padding-left: 10px; font-style: italic; }
        .subtotal-value { display: table-cell; width: 30%; text-align: right; }

        .total-row {
            display: table;
            width: 100%;
            padding: 10px;
            background: #dbeafe;
            font-weight: bold;
            font-size: 11px;
            margin-top: 5px;
        }
        .total-label { display: table-cell; width: 70%; }
        .total-value { display: table-cell; width: 30%; text-align: right; }

        .grand-total {
            display: table;
            width: 100%;
            padding: 12px;
            background: #1e40af;
            color: #fff;
            font-weight: bold;
            font-size: 12px;
            margin-top: 15px;
        }

        .balanced-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: bold;
            margin-top: 10px;
        }
        .balanced { background: #dcfce7; color: #166534; }
        .not-balanced { background: #fee2e2; color: #991b1b; }

        .comparison { font-size: 9px; color: #6b7280; }
        .positive { color: #059669; }
        .negative { color: #dc2626; }

        .footer { margin-top: 30px; text-align: center; font-size: 8px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }

        @page { margin: 10mm; }
        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            @if(isset($organization) && $organization->name)
            <div class="company-name">{{ $organization->name }}</div>
            @endif
            <div class="report-title">Balance Sheet</div>
            <div class="report-date">As of {{ $as_of_date ?? now()->format('d M Y') }}</div>
            @if(isset($compare_to))
            <div class="comparison">Compared to {{ $compare_to }}</div>
            @endif
        </div>

        <!-- Assets Section -->
        <div class="section">
            <div class="section-header">Assets</div>

            @foreach($sections['assets']['items'] ?? [] as $subType)
            <div class="sub-section">
                <div class="sub-header">{{ $subType['label'] ?? 'Other' }}</div>

                @foreach($subType['accounts'] ?? [] as $account)
                <div class="account-row">
                    <div class="account-name">
                        {{ $account['code'] }} - {{ $account['name'] }}
                    </div>
                    <div class="account-balance">
                        {{ $currency_code ?? '' }} {{ number_format((float)($account['balance'] ?? 0), 2) }}
                        @if(isset($account['change']))
                        <div class="comparison {{ $account['change'] >= 0 ? 'positive' : 'negative' }}">
                            {{ $account['change'] >= 0 ? '+' : '' }}{{ number_format((float)$account['change'], 2) }}
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach

                <div class="subtotal-row">
                    <div class="subtotal-label">Subtotal {{ $subType['label'] ?? '' }}</div>
                    <div class="subtotal-value">{{ number_format((float)($subType['subtotal'] ?? 0), 2) }}</div>
                </div>
            </div>
            @endforeach

            <div class="total-row">
                <div class="total-label">Total Assets</div>
                <div class="total-value">{{ $currency_code ?? '' }} {{ number_format((float)($sections['assets']['total'] ?? 0), 2) }}</div>
            </div>
        </div>

        <!-- Liabilities Section -->
        <div class="section">
            <div class="section-header">Liabilities</div>

            @foreach($sections['liabilities']['items'] ?? [] as $subType)
            <div class="sub-section">
                <div class="sub-header">{{ $subType['label'] ?? 'Other' }}</div>

                @foreach($subType['accounts'] ?? [] as $account)
                <div class="account-row">
                    <div class="account-name">{{ $account['code'] }} - {{ $account['name'] }}</div>
                    <div class="account-balance">{{ number_format((float)($account['balance'] ?? 0), 2) }}</div>
                </div>
                @endforeach

                <div class="subtotal-row">
                    <div class="subtotal-label">Subtotal {{ $subType['label'] ?? '' }}</div>
                    <div class="subtotal-value">{{ number_format((float)($subType['subtotal'] ?? 0), 2) }}</div>
                </div>
            </div>
            @endforeach

            <div class="total-row">
                <div class="total-label">Total Liabilities</div>
                <div class="total-value">{{ $currency_code ?? '' }} {{ number_format((float)($sections['liabilities']['total'] ?? 0), 2) }}</div>
            </div>
        </div>

        <!-- Equity Section -->
        <div class="section">
            <div class="section-header">Equity</div>

            @foreach($sections['equity']['items'] ?? [] as $item)
                @foreach($item['accounts'] ?? [] as $account)
                <div class="account-row" style="margin-left: 15px;">
                    <div class="account-name">{{ $account['code'] ?? '' }} {{ $account['code'] ? '-' : '' }} {{ $account['name'] }}</div>
                    <div class="account-balance">{{ number_format((float)($account['balance'] ?? 0), 2) }}</div>
                </div>
                @endforeach
            @endforeach

            <div class="total-row">
                <div class="total-label">Total Equity</div>
                <div class="total-value">{{ $currency_code ?? '' }} {{ number_format((float)($sections['equity']['total'] ?? 0), 2) }}</div>
            </div>
        </div>

        <!-- Grand Total -->
        <div class="grand-total">
            <div class="total-label">TOTAL LIABILITIES & EQUITY</div>
            <div class="total-value">{{ $currency_code ?? '' }} {{ number_format((float)($summary['total_liabilities_and_equity'] ?? 0), 2) }}</div>
        </div>

        <!-- Balance Check -->
        <div style="text-align: center; margin-top: 15px;">
            @if(($summary['is_balanced'] ?? false))
            <span class="balanced-badge balanced">✓ BALANCED</span>
            @else
            <span class="balanced-badge not-balanced">✗ NOT BALANCED (Difference: {{ number_format((float)($summary['difference'] ?? 0), 2) }})</span>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            @if(isset($organization) && $organization->name)
            {{ $organization->name }}
            <br>
            @endif
            Generated: {{ $generated_at ?? now()->format('d M Y H:i:s') }}
        </div>
    </div>
</body>
</html>

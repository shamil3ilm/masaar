<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Trial Balance</title>
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

        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th {
            background: #1e40af;
            color: #fff;
            padding: 10px 8px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }
        th.text-right { text-align: right; }
        td { padding: 8px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
        td.text-right { text-align: right; font-family: monospace; }
        tr:nth-child(even) { background: #f9fafb; }

        .type-asset { color: #059669; }
        .type-liability { color: #dc2626; }
        .type-equity { color: #7c3aed; }
        .type-income { color: #0891b2; }
        .type-expense { color: #ea580c; }

        .total-row {
            background: #dbeafe !important;
            font-weight: bold;
            font-size: 11px;
        }
        .total-row td { border-top: 2px solid #1e40af; padding: 12px 8px; }

        .balance-check {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .balanced { background: #dcfce7; border: 2px solid #22c55e; }
        .not-balanced { background: #fee2e2; border: 2px solid #ef4444; }
        .balance-status { font-size: 14px; font-weight: bold; }
        .balanced .balance-status { color: #166534; }
        .not-balanced .balance-status { color: #991b1b; }

        .summary-box { display: table; width: 100%; margin-top: 20px; }
        .summary-item { display: table-cell; text-align: center; padding: 15px; background: #f0f9ff; border: 1px solid #bae6fd; }
        .summary-value { font-size: 16px; font-weight: bold; color: #1e40af; }
        .summary-label { font-size: 9px; color: #666; margin-top: 5px; }

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
            <div class="report-title">Trial Balance</div>
            <div class="report-date">As of {{ $as_of_date ?? now()->format('d M Y') }}</div>
        </div>

        <!-- Trial Balance Table -->
        <table>
            <thead>
                <tr>
                    <th style="width: 15%">Code</th>
                    <th style="width: 40%">Account Name</th>
                    <th style="width: 15%">Type</th>
                    <th class="text-right" style="width: 15%">Debit</th>
                    <th class="text-right" style="width: 15%">Credit</th>
                </tr>
            </thead>
            <tbody>
                @foreach($accounts ?? [] as $account)
                <tr>
                    <td>{{ $account['code'] ?? $account['account_code'] ?? '' }}</td>
                    <td>{{ $account['name'] ?? $account['account_name'] ?? '' }}</td>
                    <td class="type-{{ $account['type'] ?? $account['account_type'] ?? 'asset' }}">
                        {{ ucfirst($account['type'] ?? $account['account_type'] ?? '') }}
                    </td>
                    <td class="text-right">
                        @if(($account['debit'] ?? 0) > 0)
                            {{ number_format((float)$account['debit'], 2) }}
                        @else
                            -
                        @endif
                    </td>
                    <td class="text-right">
                        @if(($account['credit'] ?? 0) > 0)
                            {{ number_format((float)$account['credit'], 2) }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
                @endforeach

                <!-- Totals Row -->
                <tr class="total-row">
                    <td colspan="3">TOTALS</td>
                    <td class="text-right">{{ $currency_code ?? '' }} {{ number_format((float)($summary['total_debit'] ?? $totals['debit'] ?? 0), 2) }}</td>
                    <td class="text-right">{{ $currency_code ?? '' }} {{ number_format((float)($summary['total_credit'] ?? $totals['credit'] ?? 0), 2) }}</td>
                </tr>
            </tbody>
        </table>

        <!-- Balance Check -->
        @php
            $isBalanced = $summary['is_balanced'] ?? $is_balanced ?? false;
            $difference = ($summary['total_debit'] ?? $totals['debit'] ?? 0) - ($summary['total_credit'] ?? $totals['credit'] ?? 0);
        @endphp
        <div class="balance-check {{ $isBalanced ? 'balanced' : 'not-balanced' }}">
            @if($isBalanced)
            <div class="balance-status">✓ TRIAL BALANCE IS IN BALANCE</div>
            <div style="font-size: 10px; color: #166534; margin-top: 5px;">Total Debits equal Total Credits</div>
            @else
            <div class="balance-status">✗ TRIAL BALANCE IS OUT OF BALANCE</div>
            <div style="font-size: 10px; color: #991b1b; margin-top: 5px;">
                Difference: {{ $currency_code ?? '' }} {{ number_format(abs((float)$difference), 2) }}
            </div>
            @endif
        </div>

        <!-- Summary -->
        <div class="summary-box">
            <div class="summary-item">
                <div class="summary-value">{{ $summary['account_count'] ?? count($accounts ?? []) }}</div>
                <div class="summary-label">Accounts with Balances</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ number_format((float)($summary['total_debit'] ?? $totals['debit'] ?? 0), 2) }}</div>
                <div class="summary-label">Total Debits</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ number_format((float)($summary['total_credit'] ?? $totals['credit'] ?? 0), 2) }}</div>
                <div class="summary-label">Total Credits</div>
            </div>
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

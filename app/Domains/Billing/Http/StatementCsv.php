<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http;

use App\Domains\Billing\Settings\FinanceSettings;
use App\Http\Listing\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A statement's period and currency as the page asks for them, and the
 * statement as a spreadsheet: shared by the console's and the portal's.
 */
final class StatementCsv
{
    /**
     * The currency and dates asked for: by default the customer's currency
     * and the twelve months to today.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    public static function period(Request $request, string $currency): array
    {
        $asked = strtoupper((string) $request->query('currency', ''));
        $currency = in_array($asked, FinanceSettings::billable(), true) ? $asked : $currency;
        $to = self::date($request->query('to')) ?? CarbonImmutable::now()->startOfDay();
        $from = self::date($request->query('from')) ?? $to->subYear()->addDay();

        return [$currency, $from->min($to), $to];
    }

    /**
     * @param  array{currency: string, opening: string, closing: string, lines: list<array<string, string>>}  $statement
     */
    public static function download(array $statement, string $fileName): StreamedResponse
    {
        $rows = [
            ['date' => '', 'reference' => '', 'description' => __('Opening balance'), 'debit' => '', 'credit' => '', 'balance' => $statement['opening']],
            ...$statement['lines'],
            ['date' => '', 'reference' => '', 'description' => __('Closing balance'), 'debit' => '', 'credit' => '', 'balance' => $statement['closing']],
        ];

        return CsvExport::download($fileName, [
            __('Date') => fn (array $row): string => $row['date'],
            __('Reference') => fn (array $row): string => $row['reference'],
            __('Description') => fn (array $row): string => $row['description'],
            __('Charged (:currency)', ['currency' => $statement['currency']]) => fn (array $row): string => $row['debit'],
            __('Paid or credited (:currency)', ['currency' => $statement['currency']]) => fn (array $row): string => $row['credit'],
            __('Balance (:currency)', ['currency' => $statement['currency']]) => fn (array $row): string => $row['balance'],
        ], $rows);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}

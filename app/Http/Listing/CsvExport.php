<?php

declare(strict_types=1);

namespace App\Http\Listing;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A list as a spreadsheet: exactly the rows the list's filters give, every
 * page of them, streamed so a year of invoices never sits in memory.
 *
 * The rows are whatever the caller's query yields - the same tenant-scoped,
 * filtered query the list pages through - so the file can hold nothing the
 * list could not show. A cell that a spreadsheet would read as a formula
 * (=, +, -, @, a tab or a return first) is written with a quote before it,
 * as InvoiceCsv does: a buyer named "=HYPERLINK(...)" is text, not a link.
 */
final class CsvExport
{
    /**
     * @param  array<string, Closure(mixed): (string|int|float|null)>  $columns  header => cell
     * @param  iterable<mixed>  $rows
     */
    public static function download(string $fileName, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            self::row($out, array_keys($columns));

            foreach ($rows as $row) {
                self::row($out, array_map(fn (Closure $cell): string => (string) $cell($row), array_values($columns)));
            }

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  resource  $out
     * @param  list<string>  $cells
     */
    private static function row($out, array $cells): void
    {
        fputcsv($out, array_map(
            fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) === 1 && ! is_numeric($cell) ? "'".$cell : $cell,
            $cells,
        ), escape: '');
    }
}

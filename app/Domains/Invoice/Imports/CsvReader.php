<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Imports;

use DomainException;
use SplFileObject;

/**
 * A CSV file as rows of named cells, the way a spreadsheet saves one.
 *
 * Comma, semicolon or tab separated - whichever the header row uses most -
 * in UTF-8, with or without the byte-order mark spreadsheets write. Header
 * names are compared without case or surrounding space. A row is numbered as
 * the spreadsheet shows it, the header being row 1, so a problem names the
 * row someone can find.
 */
final class CsvReader
{
    private const DELIMITERS = [',', ';', "\t"];

    /**
     * @return array{header: list<string>, rows: list<array{row: int, values: array<string, string>}>}
     *
     * @throws DomainException when the file is not a CSV this can read
     */
    public function read(string $path, int $maxRows): array
    {
        $text = (string) @file_get_contents($path);

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        if (trim($text) === '') {
            throw new DomainException(__('The file is empty.'));
        }

        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new DomainException(__('The file is not UTF-8 text. Save it from your spreadsheet as "CSV UTF-8".'));
        }

        $file = new SplFileObject('php://temp', 'w+');
        $file->fwrite($text);
        $file->rewind();
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($this->delimiter($text), '"', '');

        $header = null;
        $rows = [];
        $row = 1;

        foreach ($file as $cells) {
            if (! is_array($cells) || $cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn (mixed $name): string => mb_strtolower(trim((string) $name)), $cells);

                continue;
            }

            $row++;

            if (count($rows) >= $maxRows) {
                throw new DomainException(__('The file has more than :count rows. Split it into smaller files.', ['count' => $maxRows]));
            }

            $values = [];

            foreach ($header as $index => $name) {
                $value = trim((string) ($cells[$index] ?? ''));

                if ($name !== '' && $value !== '') {
                    $values[$name] = $value;
                }
            }

            $rows[] = ['row' => $row, 'values' => $values];
        }

        if ($header === null || array_filter($header) === []) {
            throw new DomainException(__('The file has no header row naming its columns.'));
        }

        return ['header' => array_values(array_filter($header)), 'rows' => $rows];
    }

    /**
     * The separator the header row uses most.
     */
    private function delimiter(string $text): string
    {
        $first = strtok($text, "\n") ?: '';
        $best = ',';
        $most = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($first, $delimiter);

            if ($count > $most) {
                [$best, $most] = [$delimiter, $count];
            }
        }

        return $best;
    }
}

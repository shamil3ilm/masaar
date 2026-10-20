<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Parsers;

use App\Domains\Billing\Reconciliation\DTOs\ParsedLine;
use App\Domains\Billing\Reconciliation\DTOs\ParsedStatement;
use App\Domains\Billing\Reconciliation\StatementRejected;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A bank's statement exported as CSV, read by its bank's profile (Finance
 * › Settings › Statement profiles): which delimiter, encoding, date format
 * and decimal mark it uses, how many rows come before the header, and
 * which column holds what - by its heading or its number from 1.
 *
 * The amount is one signed column, or a credit and a debit column. A CSV
 * states no account, period or balances, so the account is the one its
 * profile names.
 */
final class CsvStatementParser
{
    public const DELIMITERS = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t", 'pipe' => '|'];

    public const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd-m-Y', 'Y/m/d', 'd/m/y'];

    /** What a profile can say a column holds. */
    public const COLUMNS = ['date', 'value_date', 'amount', 'credit', 'debit', 'currency', 'reference', 'bank_reference', 'counterparty', 'description'];

    /**
     * @param  array<string, mixed>  $profile
     *
     * @throws StatementRejected
     */
    public function parse(string $bytes, array $profile): ParsedStatement
    {
        $text = TextFile::decode($bytes, (string) ($profile['encoding'] ?? 'UTF-8'));
        $rows = $this->rows($text, self::DELIMITERS[$profile['delimiter'] ?? 'comma'] ?? ',');
        $rows = array_slice($rows, max(0, (int) ($profile['skip_rows'] ?? 0)));

        if ($rows === []) {
            throw new StatementRejected(__('The file has no rows.'));
        }

        $header = array_map(fn ($cell): string => mb_strtolower(trim((string) $cell)), array_shift($rows));
        $columns = $this->columns($profile, $header);
        $format = (string) ($profile['date_format'] ?? 'Y-m-d');
        $decimal = ($profile['decimal'] ?? '.') === ',' ? ',' : '.';
        $lines = [];

        foreach ($rows as $index => $row) {
            if (implode('', array_map('trim', $row)) === '') {
                continue;
            }

            $line = $this->line($row, $columns, $format, $decimal, $index + 2 + (int) ($profile['skip_rows'] ?? 0));

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return new ParsedStatement($lines);
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $text, string $delimiter): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = array_map(fn ($cell): string => (string) $cell, $row);
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Each column the profile names, as its place in a row.
     *
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $header
     * @return array<string, int>
     *
     * @throws StatementRejected
     */
    private function columns(array $profile, array $header): array
    {
        $columns = [];

        foreach (self::COLUMNS as $column) {
            $named = trim((string) ($profile[$column] ?? ''));

            if ($named === '') {
                continue;
            }

            $place = ctype_digit($named) ? (int) $named - 1 : array_search(mb_strtolower($named), $header, true);

            if ($place === false || $place < 0) {
                throw new StatementRejected(__('The file has no ":column" column, which its profile reads the :field from.', ['column' => $named, 'field' => $column]));
            }

            $columns[$column] = $place;
        }

        if (! isset($columns['date']) || (! isset($columns['amount']) && ! isset($columns['credit'], $columns['debit']))) {
            throw new StatementRejected(__('The profile needs a date column and an amount column, or a credit and a debit column.'));
        }

        return $columns;
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $columns
     *
     * @throws StatementRejected
     */
    private function line(array $row, array $columns, string $format, string $decimal, int $number): ?ParsedLine
    {
        $cell = fn (string $column): ?string => isset($columns[$column]) && trim($row[$columns[$column]] ?? '') !== '' ? trim($row[$columns[$column]]) : null;

        $bookedOn = $this->date($cell('date'), $format, $number);
        [$amount, $direction] = $this->amount($cell, $decimal, $number);

        if ($amount === '0.00') {
            return null;
        }

        $valueOn = $cell('value_date');

        return new ParsedLine(
            bookedOn: $bookedOn,
            direction: $direction,
            amount: $amount,
            currency: $cell('currency') === null ? null : strtoupper((string) $cell('currency')),
            valueOn: $valueOn === null ? null : $this->date($valueOn, $format, $number),
            reference: $this->text($cell('reference'), 140),
            bankReference: $this->text($cell('bank_reference'), 140),
            counterpartyName: $this->text($cell('counterparty'), 140),
            description: $this->text($cell('description'), 500),
        );
    }

    /**
     * @return array{0: string, 1: string} [amount, direction]
     *
     * @throws StatementRejected
     */
    private function amount(callable $cell, string $decimal, int $number): array
    {
        if ($cell('amount') !== null || ($cell('credit') === null && $cell('debit') === null)) {
            $parsed = Amounts::parse((string) $cell('amount'), $decimal)
                ?? throw new StatementRejected(__('Row :row: ":value" is not an amount.', ['row' => $number, 'value' => mb_substr((string) $cell('amount'), 0, 30)]));

            return [$parsed[0], $parsed[1] ? ParsedLine::DEBIT : ParsedLine::CREDIT];
        }

        foreach (['credit' => ParsedLine::CREDIT, 'debit' => ParsedLine::DEBIT] as $column => $direction) {
            if ($cell($column) === null) {
                continue;
            }

            $parsed = Amounts::parse((string) $cell($column), $decimal)
                ?? throw new StatementRejected(__('Row :row: ":value" is not an amount.', ['row' => $number, 'value' => mb_substr((string) $cell($column), 0, 30)]));

            if ($parsed[0] !== '0.00') {
                return [$parsed[0], $direction];
            }
        }

        return ['0.00', ParsedLine::CREDIT];
    }

    /** @throws StatementRejected */
    private function date(?string $value, string $format, int $number): CarbonImmutable
    {
        // No overflow: 31/02 is a mistyped date, not 3 March.
        $date = $value === null ? false : DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || (int) $date->format('Y') < 2000) {
            throw new StatementRejected(__('Row :row: ":value" is not a date written :format.', ['row' => $number, 'value' => mb_substr((string) $value, 0, 30), 'format' => $format]));
        }

        return CarbonImmutable::instance($date);
    }

    private function text(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}

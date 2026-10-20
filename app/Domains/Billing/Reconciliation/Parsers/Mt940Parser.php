<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Parsers;

use App\Domains\Billing\Reconciliation\DTOs\ParsedLine;
use App\Domains\Billing\Reconciliation\DTOs\ParsedStatement;
use App\Domains\Billing\Reconciliation\StatementRejected;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A SWIFT MT940 customer statement, with or without its message blocks
 * ({1:...}{2:...}{4: ... -}), one message or several of the same account.
 *
 *   :20:   the statement's reference      :25:  the account
 *   :28C:  statement / sequence number    :60F: :60M:  opening balance
 *   :61:   a line: value date, entry date, C/D (RC/RD a reversal),
 *          amount, type, the customer's reference // the bank's, and
 *          supplementary details on the next line
 *   :86:   the line's information to the account owner
 *   :62F: :62M:  closing balance
 *
 * Amounts use a decimal comma ("1150,00"). Each message's balances must
 * add up (BalanceCheck).
 */
final class Mt940Parser
{
    /** @throws StatementRejected */
    public function parse(string $bytes): ParsedStatement
    {
        $messages = $this->messages(TextFile::decode($bytes));

        if ($messages === []) {
            throw new StatementRejected(__('The file is not an MT940 statement: it has no :20: statement reference.'));
        }

        $lines = [];
        $first = null;
        $opening = $closing = null;

        foreach ($messages as $fields) {
            $account = trim((string) $this->field($fields, '25'));
            $open = $this->balance($this->field($fields, '60F') ?? $this->field($fields, '60M'));
            $close = $this->balance($this->field($fields, '62F') ?? $this->field($fields, '62M'));

            if ($open === null || $close === null) {
                throw new StatementRejected(__('An MT940 statement has no opening or closing balance (:60F: and :62F:).'));
            }

            $first ??= [
                'account' => $account,
                'currency' => $open['currency'],
                'reference' => trim((string) $this->field($fields, '20')).'/'.trim((string) $this->field($fields, '28C')),
                'from' => $open['date'],
            ];

            if ($account !== $first['account']) {
                throw new StatementRejected(__('The file holds statements of more than one account. Upload each account\'s on its own.'));
            }

            $messageLines = $this->lines($fields, $open['currency']);
            BalanceCheck::assert($open['amount'], $close['amount'], $messageLines);
            $opening ??= $open['amount'];
            $closing = $close['amount'];
            $periodTo = $close['date'];
            array_push($lines, ...$messageLines);
        }

        return new ParsedStatement(
            lines: $lines,
            accountIdentifier: $first['account'] === '' ? null : $first['account'],
            currency: $first['currency'],
            statementReference: mb_substr(count($messages) > 1 ? $first['reference'].'+'.(count($messages) - 1) : $first['reference'], 0, 120),
            periodFrom: $first['from'],
            periodTo: $periodTo ?? null,
            openingBalance: $opening,
            closingBalance: $closing,
        );
    }

    /**
     * Each message's fields in order, as [tag, value] pairs.
     *
     * @return list<list<array{0: string, 1: string}>>
     */
    private function messages(string $text): array
    {
        $bodies = preg_match_all('/\{4:\s*\n(.*?)\n-\}/s', $text, $blocks) > 0
            ? $blocks[1]
            : preg_split('/(?=^:20:)/m', $text, -1, PREG_SPLIT_NO_EMPTY);

        $messages = [];

        foreach ($bodies as $body) {
            $fields = [];

            foreach (explode("\n", $body) as $row) {
                if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $row, $match) === 1) {
                    $fields[] = [$match[1], $match[2]];
                } elseif ($fields !== [] && trim($row) !== '' && trim($row) !== '-') {
                    $fields[count($fields) - 1][1] .= "\n".$row;
                }
            }

            if ($this->field($fields, '20') !== null) {
                $messages[] = $fields;
            }
        }

        return $messages;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $fields
     * @return list<ParsedLine>
     *
     * @throws StatementRejected
     */
    private function lines(array $fields, string $currency): array
    {
        $lines = [];

        foreach ($fields as $index => [$tag, $value]) {
            if ($tag !== '61') {
                continue;
            }

            $next = $fields[$index + 1] ?? null;
            $lines[] = $this->line($value, $next !== null && $next[0] === '86' ? $next[1] : null, $currency);
        }

        return $lines;
    }

    /** @throws StatementRejected */
    private function line(string $value, ?string $information, string $currency): ParsedLine
    {
        if (preg_match('/^(\d{6})(\d{4})?(RC|RD|C|D)([A-Z])?(\d{1,15},\d{0,2})([A-Z][A-Z0-9]{3})([^\n]*?)(?:\/\/([^\n]*))?(?:\n(.*))?$/s', $value, $match) !== 1) {
            throw new StatementRejected(__('A statement line (:61:) is not in the MT940 layout: :line', ['line' => mb_substr(strtok($value, "\n") ?: '', 0, 40)]));
        }

        $valueOn = $this->date($match[1]) ?? throw new StatementRejected(__('A statement line has an impossible date: :date', ['date' => $match[1]]));
        $bookedOn = $match[2] === '' ? $valueOn : $this->entryDate($valueOn, $match[2]);
        $amount = Amounts::parse(str_ends_with($match[5], ',') ? $match[5].'00' : $match[5], ',')
            ?? throw new StatementRejected(__('A statement line\'s amount is not an amount: :amount', ['amount' => $match[5]]));
        $reference = trim($match[7]);

        return new ParsedLine(
            bookedOn: $bookedOn,
            // RD reverses a debit: money back in. RC reverses a credit.
            direction: in_array($match[3], ['C', 'RD'], true) ? ParsedLine::CREDIT : ParsedLine::DEBIT,
            amount: $amount[0],
            currency: $currency,
            valueOn: $valueOn,
            reference: $reference === '' || strtoupper($reference) === 'NONREF' ? null : mb_substr($reference, 0, 140),
            bankReference: isset($match[8]) && trim($match[8]) !== '' ? mb_substr(trim($match[8]), 0, 140) : null,
            description: $this->description($match[9] ?? null, $information),
        );
    }

    /**
     * @return array{date: CarbonImmutable, currency: string, amount: string}|null
     */
    private function balance(?string $value): ?array
    {
        if ($value === null || preg_match('/^([CD])(\d{6})([A-Z]{3})(\d{1,15},\d{0,2})/', trim($value), $match) !== 1) {
            return null;
        }

        $amount = Amounts::parse(str_ends_with($match[4], ',') ? $match[4].'00' : $match[4], ',');
        $date = $this->date($match[2]);

        if ($amount === null || $date === null) {
            return null;
        }

        return ['date' => $date, 'currency' => $match[3], 'amount' => ($match[1] === 'D' && $amount[0] !== '0.00' ? '-' : '').$amount[0]];
    }

    private function date(string $yymmdd): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!ymd', $yymmdd, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('ymd') === $yymmdd ? $date : null;
    }

    /** The entry date's year is the value date's, unless the two straddle New Year. */
    private function entryDate(CarbonImmutable $valueOn, string $mmdd): CarbonImmutable
    {
        $year = $valueOn->year;
        $month = (int) substr($mmdd, 0, 2);

        if ($month === 1 && $valueOn->month === 12) {
            $year++;
        } elseif ($month === 12 && $valueOn->month === 1) {
            $year--;
        }

        return $this->date(substr((string) $year, 2).$mmdd) ?? $valueOn;
    }

    private function description(?string $supplementary, ?string $information): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', trim(($supplementary ?? '').' '.($information ?? ''))) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $fields
     */
    private function field(array $fields, string $tag): ?string
    {
        foreach ($fields as [$name, $value]) {
            if ($name === $tag) {
                return $value;
            }
        }

        return null;
    }
}

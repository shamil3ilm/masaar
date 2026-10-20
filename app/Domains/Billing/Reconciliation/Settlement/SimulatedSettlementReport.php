<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Settlement;

use App\Domains\Billing\Reconciliation\Contracts\SettlementReport;
use App\Domains\Billing\Reconciliation\DTOs\ParsedLine;
use App\Domains\Billing\Reconciliation\DTOs\ParsedStatement;
use App\Domains\Billing\Reconciliation\Parsers\Amounts;
use App\Domains\Billing\Reconciliation\Parsers\TextFile;
use App\Domains\Billing\Reconciliation\StatementRejected;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The card gateway simulator's settlement report (billing.gateway =
 * simulator): a CSV with a header row of
 *
 *   settlement_id, settled_on, transaction_id, reference, currency,
 *   gross, fee, net
 *
 * one row per card transaction, every row of one settlement. A refund has
 * a negative gross and net. It is the layout a provider's report is mapped
 * to, so the whole path - import, match to the card payment, fees - runs
 * with no provider contracted.
 */
final class SimulatedSettlementReport implements SettlementReport
{
    public const HEADER = ['settlement_id', 'settled_on', 'transaction_id', 'reference', 'currency', 'gross', 'fee', 'net'];

    public function available(): bool
    {
        return true;
    }

    public function accepts(): string
    {
        return '.csv,text/csv';
    }

    public function parse(string $bytes): ParsedStatement
    {
        $rows = array_map(
            fn (string $row): array => str_getcsv($row, ',', '"', ''),
            array_values(array_filter(explode("\n", TextFile::decode($bytes)), fn (string $row): bool => trim($row) !== '')),
        );
        $header = array_map(fn ($cell): string => strtolower(trim((string) $cell)), array_shift($rows) ?? []);

        if ($header !== self::HEADER) {
            throw new StatementRejected(__('The file is not a settlement report: its first row must be :header.', ['header' => implode(',', self::HEADER)]));
        }

        $settlement = null;
        $lines = [];

        foreach ($rows as $index => $row) {
            $cells = array_combine(self::HEADER, array_pad(array_map(fn ($cell): string => trim((string) $cell), array_slice($row, 0, 8)), 8, ''));
            $settlement ??= $cells['settlement_id'];

            if ($cells['settlement_id'] === '' || $cells['settlement_id'] !== $settlement) {
                throw new StatementRejected(__('Row :row is of another settlement. A report is one settlement.', ['row' => $index + 2]));
            }

            $lines[] = $this->line($cells, $index + 2);
        }

        if ($lines === []) {
            throw new StatementRejected(__('The file has no rows.'));
        }

        $dates = array_map(fn (ParsedLine $line): CarbonImmutable => $line->bookedOn, $lines);

        return new ParsedStatement(
            lines: $lines,
            statementReference: mb_substr((string) $settlement, 0, 120),
            periodFrom: min($dates),
            periodTo: max($dates),
        );
    }

    /**
     * @param  array<string, string>  $cells
     *
     * @throws StatementRejected
     */
    private function line(array $cells, int $row): ParsedLine
    {
        $gross = Amounts::parse($cells['gross']);
        $fee = Amounts::parse($cells['fee']);
        $net = Amounts::parse($cells['net']);

        if ($gross === null || $fee === null || $net === null || $gross[0] === '0.00') {
            throw new StatementRejected(__('Row :row: the gross, fee and net must be amounts.', ['row' => $row]));
        }

        $signed = fn (array $amount): string => ($amount[1] ? '-' : '').$amount[0];

        if (bccomp(bcsub($signed($gross), $fee[0], 2), $signed($net), 2) !== 0) {
            throw new StatementRejected(__('Row :row does not add up: the gross less the fee is not the net.', ['row' => $row]));
        }

        if (preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $cells['transaction_id']) !== 1) {
            throw new StatementRejected(__('Row :row has no transaction id.', ['row' => $row]));
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cells['settled_on'], new DateTimeZone('UTC'));

        if ($date === false || $date->format('Y-m-d') !== $cells['settled_on']) {
            throw new StatementRejected(__('Row :row: ":value" is not a date written :format.', ['row' => $row, 'value' => mb_substr($cells['settled_on'], 0, 30), 'format' => 'Y-m-d']));
        }

        if (preg_match('/^[A-Z]{3}$/', strtoupper($cells['currency'])) !== 1) {
            throw new StatementRejected(__('Row :row has no currency.', ['row' => $row]));
        }

        return new ParsedLine(
            bookedOn: CarbonImmutable::instance($date),
            direction: $gross[1] ? ParsedLine::DEBIT : ParsedLine::CREDIT,
            amount: $gross[0],
            currency: strtoupper($cells['currency']),
            reference: $cells['reference'] === '' ? null : mb_substr($cells['reference'], 0, 140),
            bankReference: $cells['transaction_id'],
            gatewayReference: $cells['transaction_id'],
            fee: $fee[0],
        );
    }
}

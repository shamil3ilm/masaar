<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Parsers;

use App\Domains\Billing\Reconciliation\DTOs\ParsedLine;
use App\Domains\Billing\Reconciliation\DTOs\ParsedStatement;
use App\Domains\Billing\Reconciliation\StatementRejected;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * An ISO 20022 bank-to-customer statement (camt.053, any version from
 * 001.02 on): read by element name, so the version's namespace does not
 * matter.
 *
 * Per statement (Stmt): its id, the account (Acct/Id/IBAN, or Othr/Id) and
 * currency, the period (FrToDt), and the opening and closing booked
 * balances (Bal with OPBD/PRCD and CLBD). Per entry (Ntry): booked entries
 * only (Sts BOOK; pending and information-only entries are not money yet),
 * its amount and CRDT/DBIT, booking and value dates, the bank's reference
 * (AcctSvcrRef), and from its transaction details the end-to-end id, the
 * remittance information and the payer. An entry batching several
 * transactions, each with its own amount, is one line per transaction.
 *
 * Read safely: a document type declaration - the way an XML file pulls in
 * external entities or expands one into gigabytes - is refused outright,
 * and the parser never touches the network.
 */
final class Camt053Parser
{
    /** @throws StatementRejected */
    public function parse(string $bytes): ParsedStatement
    {
        $xpath = $this->load($bytes);
        $statements = $xpath->query('/*[local-name()="Document"]/*[local-name()="BkToCstmrStmt"]/*[local-name()="Stmt"]');

        if ($statements === false || $statements->length === 0) {
            throw new StatementRejected(__('The file is not a camt.053 bank statement: it has no statement in it.'));
        }

        $lines = [];
        $first = null;
        $periodFrom = $periodTo = null;
        $opening = $closing = null;

        foreach ($statements as $statement) {
            /** @var DOMElement $statement */
            $account = $this->text($xpath, 'Acct/Id/IBAN', $statement) ?? $this->text($xpath, 'Acct/Id/Othr/Id', $statement);
            $currency = $this->text($xpath, 'Acct/Ccy', $statement);
            $first ??= ['account' => $account, 'currency' => $currency, 'id' => $this->text($xpath, 'Id', $statement)];

            if ($account !== $first['account']) {
                throw new StatementRejected(__('The file holds statements of more than one account. Upload each account\'s on its own.'));
            }

            $from = $this->date($this->text($xpath, 'FrToDt/FrDtTm', $statement));
            $to = $this->date($this->text($xpath, 'FrToDt/ToDtTm', $statement));
            $periodFrom = $from !== null && ($periodFrom === null || $from->lt($periodFrom)) ? $from : $periodFrom;
            $periodTo = $to !== null && ($periodTo === null || $to->gt($periodTo)) ? $to : $periodTo;

            $statementLines = [];

            foreach ($this->nodes($xpath, 'Ntry', $statement) as $entry) {
                array_push($statementLines, ...$this->entry($xpath, $entry, $currency));
            }

            [$open, $close] = [$this->balance($xpath, $statement, ['OPBD', 'PRCD']), $this->balance($xpath, $statement, ['CLBD'])];
            BalanceCheck::assert($open, $close, $statementLines);
            $opening ??= $open;
            $closing = $close ?? $closing;
            array_push($lines, ...$statementLines);
        }

        return new ParsedStatement(
            lines: $lines,
            accountIdentifier: $first['account'],
            currency: $first['currency'] === null ? null : strtoupper($first['currency']),
            statementReference: $first['id'] === null ? null : mb_substr($statements->length > 1 ? $first['id'].'+'.($statements->length - 1) : $first['id'], 0, 120),
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            openingBalance: $opening,
            closingBalance: $closing,
        );
    }

    /** @throws StatementRejected */
    private function load(string $bytes): DOMXPath
    {
        // An XML file declares its own encoding; only the byte-order mark
        // and stray text before the declaration are taken off.
        $xml = ltrim(str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes);

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw new StatementRejected(__('The file declares a document type, which a bank statement never does. It was not read.'));
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $document->doctype !== null) {
            throw new StatementRejected(__('The file is not well-formed XML.'));
        }

        return new DOMXPath($document);
    }

    /**
     * @return list<ParsedLine>
     *
     * @throws StatementRejected
     */
    private function entry(DOMXPath $xpath, DOMElement $entry, ?string $currency): array
    {
        $status = $this->text($xpath, 'Sts/Cd', $entry) ?? $this->text($xpath, 'Sts', $entry);

        if ($status !== null && strtoupper($status) !== 'BOOK') {
            return [];
        }

        $direction = $this->direction($this->text($xpath, 'CdtDbtInd', $entry));
        $bookedOn = $this->date($this->text($xpath, 'BookgDt/Dt', $entry) ?? $this->text($xpath, 'BookgDt/DtTm', $entry))
            ?? throw new StatementRejected(__('An entry has no booking date.'));
        $valueOn = $this->date($this->text($xpath, 'ValDt/Dt', $entry) ?? $this->text($xpath, 'ValDt/DtTm', $entry));
        $bankReference = $this->text($xpath, 'AcctSvcrRef', $entry);
        $information = $this->text($xpath, 'AddtlNtryInf', $entry);
        $details = $this->nodes($xpath, 'NtryDtls/TxDtls', $entry);

        $base = fn (string $amount, ?string $lineCurrency, ?DOMElement $detail) => new ParsedLine(
            bookedOn: $bookedOn,
            direction: $direction,
            amount: $amount,
            currency: $lineCurrency ?? $currency,
            valueOn: $valueOn,
            reference: $this->clip($detail === null ? null : ($this->text($xpath, 'Refs/EndToEndId', $detail) ?? $this->text($xpath, 'RmtInf/Strd/CdtrRefInf/Ref', $detail)), 140),
            bankReference: $this->clip($bankReference ?? ($detail === null ? null : $this->text($xpath, 'Refs/AcctSvcrRef', $detail)), 140),
            counterpartyName: $this->clip($detail === null ? null : $this->party($xpath, $detail, $direction), 140),
            counterpartyAccount: $this->clip($detail === null ? null : $this->partyAccount($xpath, $detail, $direction), 64),
            description: $this->clip(trim(implode(' ', array_filter([
                $detail === null ? null : implode(' ', $this->texts($xpath, 'RmtInf/Ustrd', $detail)),
                $information,
            ]))) ?: null, 500),
        );

        // Several transactions in one entry, each with its own amount: one
        // line each. Otherwise the entry is the line.
        if (count($details) > 1 && collect($details)->every(fn (DOMElement $detail): bool => $this->amountNode($xpath, $detail) !== null)) {
            return array_map(fn (DOMElement $detail): ParsedLine => $base(...[...$this->amount($this->amountNode($xpath, $detail)), $detail]), $details);
        }

        return [$base(...[...$this->amount($this->first($xpath, 'Amt', $entry)), $details[0] ?? null])];
    }

    private function amountNode(DOMXPath $xpath, DOMElement $detail): ?DOMElement
    {
        return $this->first($xpath, 'Amt', $detail) ?? $this->first($xpath, 'AmtDtls/TxAmt/Amt', $detail);
    }

    /**
     * @return array{0: string, 1: ?string} [amount, currency]
     *
     * @throws StatementRejected
     */
    private function amount(?DOMElement $node): array
    {
        $parsed = $node === null ? null : Amounts::parse($node->textContent);

        if ($parsed === null || $parsed[1]) {
            throw new StatementRejected(__('An entry\'s amount is missing or is not an amount.'));
        }

        $currency = $node->getAttribute('Ccy');

        return [$parsed[0], $currency === '' ? null : strtoupper($currency)];
    }

    /**
     * The opening or closing booked balance, signed: a debit balance is
     * below zero.
     *
     * @param  list<string>  $codes
     */
    private function balance(DOMXPath $xpath, DOMElement $statement, array $codes): ?string
    {
        foreach ($this->nodes($xpath, 'Bal', $statement) as $balance) {
            if (! in_array(strtoupper((string) $this->text($xpath, 'Tp/CdOrPrtry/Cd', $balance)), $codes, true)) {
                continue;
            }

            $parsed = Amounts::parse((string) $this->text($xpath, 'Amt', $balance));

            if ($parsed === null) {
                return null;
            }

            return (strtoupper((string) $this->text($xpath, 'CdtDbtInd', $balance)) === 'DBIT' && $parsed[0] !== '0.00' ? '-' : '').$parsed[0];
        }

        return null;
    }

    /** @throws StatementRejected */
    private function direction(?string $indicator): string
    {
        return match (strtoupper((string) $indicator)) {
            'CRDT' => ParsedLine::CREDIT,
            'DBIT' => ParsedLine::DEBIT,
            default => throw new StatementRejected(__('An entry is neither a credit nor a debit.')),
        };
    }

    /** Who sent the money in, or who it went to. */
    private function party(DOMXPath $xpath, DOMElement $detail, string $direction): ?string
    {
        $party = $direction === ParsedLine::CREDIT ? 'Dbtr' : 'Cdtr';

        return $this->text($xpath, "RltdPties/{$party}/Nm", $detail) ?? $this->text($xpath, "RltdPties/{$party}/Pty/Nm", $detail);
    }

    private function partyAccount(DOMXPath $xpath, DOMElement $detail, string $direction): ?string
    {
        $account = $direction === ParsedLine::CREDIT ? 'DbtrAcct' : 'CdtrAcct';

        return $this->text($xpath, "RltdPties/{$account}/Id/IBAN", $detail) ?? $this->text($xpath, "RltdPties/{$account}/Id/Othr/Id", $detail);
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr($value, 0, 10), 'UTC')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<DOMElement>
     */
    private function nodes(DOMXPath $xpath, string $path, DOMElement $context): array
    {
        $found = $xpath->query($this->path($path), $context);

        return $found === false ? [] : array_values(array_filter(iterator_to_array($found), fn ($node): bool => $node instanceof DOMElement));
    }

    private function first(DOMXPath $xpath, string $path, DOMElement $context): ?DOMElement
    {
        return $this->nodes($xpath, $path, $context)[0] ?? null;
    }

    private function text(DOMXPath $xpath, string $path, DOMElement $context): ?string
    {
        $value = trim((string) $this->first($xpath, $path, $context)?->textContent);

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private function texts(DOMXPath $xpath, string $path, DOMElement $context): array
    {
        return array_values(array_filter(array_map(fn (DOMElement $node): string => trim($node->textContent), $this->nodes($xpath, $path, $context))));
    }

    /** "Acct/Id/IBAN" as a namespace-blind relative path. */
    private function path(string $path): string
    {
        return implode('/', array_map(fn (string $step): string => '*[local-name()="'.$step.'"]', explode('/', $path)));
    }

    private function clip(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr(preg_replace('/\s+/u', ' ', $value) ?? $value, 0, $length);
    }
}

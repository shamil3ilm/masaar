<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Reconciliation\Contracts\SettlementReport;
use App\Domains\Billing\Reconciliation\DTOs\ParsedLine;
use App\Domains\Billing\Reconciliation\DTOs\ParsedStatement;
use App\Domains\Billing\Reconciliation\LineStatus;
use App\Domains\Billing\Reconciliation\Models\StatementImport;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Reconciliation\Parsers\Camt053Parser;
use App\Domains\Billing\Reconciliation\Parsers\CsvStatementParser;
use App\Domains\Billing\Reconciliation\Parsers\Mt940Parser;
use App\Domains\Billing\Reconciliation\StatementKind;
use App\Domains\Billing\Reconciliation\StatementRejected;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Billing\Settings\Iban;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bank statements and card settlement reports brought in, each once.
 *
 * A file is read whole before anything is kept, and refused whole when
 * anything is wrong with it: not the format it was said to be, a
 * document type in its XML, balances that do not add up, an account or a
 * currency that is not the bank account's in Finance › Settings, or more
 * lines than a statement has. The same file twice, or a statement whose
 * own id was already brought in from that account, is refused; a line
 * already brought in by an overlapping statement is counted and skipped,
 * never kept twice.
 *
 * The file is kept on the private imports disk, never served, and removed
 * after billing.reconciliation.keep_days (prune()); the import's record
 * and its lines stay. Money out is kept and set aside at once: it is not
 * a customer's payment. Money in waits for matching (Matcher).
 */
class StatementImports
{
    public const DISK = 'imports';

    private const FOLDER = 'statements';

    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly CsvStatementParser $csv,
        private readonly Camt053Parser $camt,
        private readonly Mt940Parser $mt940,
        private readonly SettlementReport $settlement,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  string|null  $bankAccount  the Finance › Settings account a camt.053 or MT940 statement is of
     * @param  string|null  $profile  the statement profile a CSV is read by
     *
     * @throws StatementRejected saying why nothing was kept
     */
    public function import(StatementKind $kind, string $fileName, string $bytes, ?string $bankAccount, ?string $profile, ?string $actorId): StatementImport
    {
        if (strlen($bytes) > (int) config('billing.reconciliation.max_kilobytes') * 1024) {
            throw new StatementRejected(__('The file is larger than a statement is. Split it by period and upload each part.'));
        }

        $hash = hash('sha256', $bytes);
        $earlier = StatementImport::query()->where('file_hash', $hash)->first();

        if ($earlier !== null) {
            throw new StatementRejected(__('This file was already imported on :date.', ['date' => $earlier->created_at?->toDateString()]));
        }

        [$source, $account, $profileRow] = $this->source($kind, $bankAccount, $profile);
        $parsed = $this->parse($kind, $bytes, $profileRow);
        $this->check($kind, $parsed, $account);

        if ($parsed->statementReference !== null && StatementImport::query()->where('source', $source)->where('statement_reference', $parsed->statementReference)->exists()) {
            throw new StatementRejected(__('Statement :reference of this account was already imported.', ['reference' => $parsed->statementReference]));
        }

        return $this->keep($kind, $fileName, $bytes, $hash, $source, $account, $profileRow, $parsed, $actorId);
    }

    /**
     * Remove kept files past their keeping period. The imports and their
     * lines stay.
     *
     * @return int how many files were removed
     */
    public function prune(): int
    {
        $removed = 0;

        StatementImport::query()
            ->whereNotNull('file_path')
            ->where('created_at', '<', now()->subDays((int) config('billing.reconciliation.keep_days')))
            ->chunkById(100, function (Collection $imports) use (&$removed): void {
                foreach ($imports as $import) {
                    Storage::disk(self::DISK)->delete((string) $import->file_path);
                    $import->forceFill(['file_path' => null, 'file_removed_at' => now()])->save();
                    $removed++;
                }
            });

        return $removed;
    }

    /**
     * Where the statement is from, the bank account it is of, and the
     * profile a CSV is read by.
     *
     * @return array{0: string, 1: array<string, string>|null, 2: array<string, string>|null}
     *
     * @throws StatementRejected
     */
    private function source(StatementKind $kind, ?string $bankAccount, ?string $profile): array
    {
        if ($kind === StatementKind::Settlement) {
            if (! $this->settlement->available()) {
                throw new StatementRejected(__('There is no card gateway, so there is no settlement report to import.'));
            }

            return [StatementImport::GATEWAY, null, null];
        }

        $profileRow = null;

        if ($kind === StatementKind::Csv) {
            $profileRow = $profile === null ? null : $this->settings->statementProfile($profile);
            $profileRow ?? throw new StatementRejected(__('Choose the statement profile that reads this bank\'s CSV. Add one in Finance › Settings.'));
            $bankAccount = $profileRow['bank_account'];
        }

        $account = collect($this->settings->bankAccounts())->firstWhere('key', (string) $bankAccount)
            ?? throw new StatementRejected(__('Choose which of the platform\'s bank accounts this is the statement of.'));

        return [$account['key'], $account, $profileRow];
    }

    /**
     * @param  array<string, string>|null  $profile
     *
     * @throws StatementRejected
     */
    private function parse(StatementKind $kind, string $bytes, ?array $profile): ParsedStatement
    {
        try {
            $parsed = match ($kind) {
                StatementKind::Csv => $this->csv->parse($bytes, (array) $profile),
                StatementKind::Camt053 => $this->camt->parse($bytes),
                StatementKind::Mt940 => $this->mt940->parse($bytes),
                StatementKind::Settlement => $this->settlement->parse($bytes),
            };
        } catch (StatementRejected $e) {
            throw $e;
        } catch (Throwable $e) {
            // A reader that fell over on a file it was never meant to read:
            // the file is refused, and the fault is reported.
            report($e);

            throw new StatementRejected(__('The file could not be read as :kind.', ['kind' => $kind->label()]));
        }

        if ($parsed->lines === []) {
            throw new StatementRejected(__('The statement has no lines.'));
        }

        if (count($parsed->lines) > (int) config('billing.reconciliation.max_lines')) {
            throw new StatementRejected(__('The statement has more than :count lines. Split it by period and upload each part.', ['count' => (int) config('billing.reconciliation.max_lines')]));
        }

        return $parsed;
    }

    /**
     * The statement is the account's, in its currency; a settlement's
     * lines are in currencies the platform bills in.
     *
     * @param  array<string, string>|null  $account
     *
     * @throws StatementRejected
     */
    private function check(StatementKind $kind, ParsedStatement $parsed, ?array $account): void
    {
        if ($account === null) {
            foreach ($parsed->lines as $line) {
                if (! in_array((string) $line->currency, FinanceSettings::billable(), true)) {
                    throw new StatementRejected(__('A line is in :currency, which the platform does not bill in.', ['currency' => (string) $line->currency]));
                }
            }

            return;
        }

        if ($parsed->accountIdentifier !== null && ! $this->sameAccount($parsed->accountIdentifier, $account['iban'])) {
            throw new StatementRejected(__('The statement is of account :file, not of :account at :bank.', [
                'file' => $parsed->accountIdentifier, 'account' => Iban::format($account['iban']), 'bank' => $account['bank_name'],
            ]));
        }

        foreach ([$parsed->currency, ...array_map(fn (ParsedLine $line): ?string => $line->currency, $parsed->lines)] as $currency) {
            if ($currency !== null && strtoupper($currency) !== $account['currency']) {
                throw new StatementRejected(__('The statement has money in :currency; the account at :bank is in :account.', [
                    'currency' => strtoupper($currency), 'bank' => $account['bank_name'], 'account' => $account['currency'],
                ]));
            }
        }
    }

    /**
     * The account a file names, against the IBAN in Finance › Settings: the
     * IBAN itself, or - as an MT940 :25: often has it - the bank's code and
     * the account number the IBAN ends with.
     */
    private function sameAccount(string $named, string $iban): bool
    {
        $named = strtoupper((string) preg_replace('/\s+/', '', $named));

        if ($named === $iban) {
            return true;
        }

        $number = preg_replace('/[^A-Z0-9]/', '', (string) Str::afterLast($named, '/'));

        return strlen((string) $number) >= 6 && str_ends_with($iban, (string) $number);
    }

    /**
     * @param  array<string, string>|null  $account
     * @param  array<string, string>|null  $profile
     *
     * @throws StatementRejected
     */
    private function keep(StatementKind $kind, string $fileName, string $bytes, string $hash, string $source, ?array $account, ?array $profile, ParsedStatement $parsed, ?string $actorId): StatementImport
    {
        $lines = $this->fingerprinted($source, $parsed->lines);
        $known = $this->known($source, array_keys($lines));
        $path = self::FOLDER.'/'.Str::uuid().'.'.($kind === StatementKind::Camt053 ? 'xml' : 'txt');

        Storage::disk(self::DISK)->put($path, $bytes);

        try {
            $import = DB::transaction(function () use ($kind, $fileName, $bytes, $hash, $source, $account, $profile, $parsed, $lines, $known, $path, $actorId): StatementImport {
                $import = new StatementImport([
                    'kind' => $kind,
                    'source' => $source,
                    'profile' => $profile['key'] ?? null,
                    'account_identifier' => $parsed->accountIdentifier === null ? null : mb_substr($parsed->accountIdentifier, 0, 64),
                    'currency' => $account['currency'] ?? ($parsed->currency === null ? null : strtoupper($parsed->currency)),
                    'statement_reference' => $parsed->statementReference,
                    'period_from' => $parsed->periodFrom?->toDateString(),
                    'period_to' => $parsed->periodTo?->toDateString(),
                    'opening_balance' => $parsed->openingBalance,
                    'closing_balance' => $parsed->closingBalance,
                    'file_hash' => $hash,
                    'file_path' => $path,
                    'file_name' => mb_substr(basename(str_replace('\\', '/', $fileName)), 0, 255),
                    'file_size' => strlen($bytes),
                    'lines_total' => count($lines),
                    'lines_new' => count($lines) - count($known),
                    'lines_duplicate' => count($known),
                ]);
                $import->forceFill(['created_by' => $actorId])->save();

                $this->insertLines($import, $lines, $known, $account['currency'] ?? null);

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            Storage::disk(self::DISK)->delete($path);

            // The same file, or the same lines, brought in by someone else a
            // moment before.
            throw new StatementRejected(__('This statement was imported a moment ago.'));
        } catch (QueryException $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }

        $this->audit->logChange('finance.statement.imported', 'StatementImport', (string) $import->id, null, [
            'kind' => $kind->value,
            'source' => $source,
            'statement_reference' => $parsed->statementReference,
            'lines_new' => $import->lines_new,
            'lines_duplicate' => $import->lines_duplicate,
        ], userId: $actorId);

        return $import;
    }

    /**
     * Each line by its fingerprint: what it is - its date, direction,
     * amount, currency, references and text - and which of the identical
     * lines it is, so two genuinely identical payments on one day are both
     * kept and an overlapping statement's copy of either is not.
     *
     * @param  list<ParsedLine>  $parsed
     * @return array<string, array{line: ParsedLine, sequence: int}>
     */
    private function fingerprinted(string $source, array $parsed): array
    {
        $lines = [];
        $seen = [];

        foreach ($parsed as $sequence => $line) {
            $base = implode('|', [
                $source, $line->bookedOn->toDateString(), $line->direction, $line->amount, (string) $line->currency,
                (string) $line->bankReference, (string) $line->reference, (string) $line->gatewayReference,
                mb_strtolower((string) preg_replace('/\s+/u', ' ', (string) $line->description)),
            ]);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $lines[hash('sha256', $base.'|'.$seen[$base])] = ['line' => $line, 'sequence' => $sequence + 1];
        }

        return $lines;
    }

    /**
     * The fingerprints of these already kept from the source.
     *
     * @param  list<string>  $hashes
     * @return array<string, true>
     */
    private function known(string $source, array $hashes): array
    {
        $known = [];

        foreach (array_chunk($hashes, 500) as $chunk) {
            foreach (StatementLine::query()->where('source', $source)->whereIn('line_hash', $chunk)->pluck('line_hash') as $hash) {
                $known[(string) $hash] = true;
            }
        }

        return $known;
    }

    /**
     * @param  array<string, array{line: ParsedLine, sequence: int}>  $lines
     * @param  array<string, true>  $known
     */
    private function insertLines(StatementImport $import, array $lines, array $known, ?string $currency): void
    {
        $now = now();
        $rows = [];

        foreach ($lines as $hash => ['line' => $line, 'sequence' => $sequence]) {
            if (isset($known[$hash])) {
                continue;
            }

            $credit = $line->direction === ParsedLine::CREDIT;
            $rows[] = [
                'id' => (string) Str::uuid(),
                'import_id' => $import->id,
                'source' => $import->source,
                'line_hash' => $hash,
                'sequence' => $sequence,
                'booked_on' => $line->bookedOn->toDateString(),
                'value_on' => $line->valueOn?->toDateString(),
                'direction' => $line->direction,
                'amount' => $line->amount,
                'currency' => strtoupper((string) ($line->currency ?? $currency)),
                'reference' => $line->reference,
                'bank_reference' => $line->bankReference,
                'counterparty_name' => $line->counterpartyName,
                'counterparty_account' => $line->counterpartyAccount,
                'description' => $line->description,
                'gateway_reference' => $line->gatewayReference,
                'fee_amount' => $line->fee,
                // Money out is not a customer's payment: set aside at once,
                // by nobody, and brought back by hand if it ever is.
                'status' => $credit ? LineStatus::Unmatched->value : LineStatus::Ignored->value,
                'ignored_at' => $credit ? null : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            StatementLine::query()->insert($chunk);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class JournalService
{
    /**
     * Create a journal entry with lines.
     *
     * @param array $entryData Entry header data
     * @param array $lines Array of line items [['account_id' => X, 'debit' => 0, 'credit' => 100], ...]
     * @return JournalEntry
     * @throws InvalidArgumentException
     */
    public function createEntry(array $entryData, array $lines): JournalEntry
    {
        $this->validateLines($lines);

        return DB::transaction(function () use ($entryData, $lines) {
            // Set fiscal year if not provided
            if (!isset($entryData['fiscal_year_id'])) {
                $fiscalYear = FiscalYear::forDate(
                    $entryData['organization_id'],
                    $entryData['entry_date'] ?? now()
                );

                if (!$fiscalYear) {
                    throw new InvalidArgumentException('No fiscal year found for the entry date.');
                }

                if ($fiscalYear->is_closed) {
                    throw new InvalidArgumentException('Cannot create entry in a closed fiscal year.');
                }

                $entryData['fiscal_year_id'] = $fiscalYear->id;
            }

            // Create the entry
            $entry = JournalEntry::create($entryData);

            // Create lines
            foreach ($lines as $index => $lineData) {
                $entry->lines()->create([
                    'account_id' => $lineData['account_id'],
                    'description' => $lineData['description'] ?? null,
                    'debit' => $lineData['debit'] ?? 0,
                    'credit' => $lineData['credit'] ?? 0,
                    'cost_center_id' => $lineData['cost_center_id'] ?? null,
                    'contact_id' => $lineData['contact_id'] ?? null,
                    'line_order' => $lineData['line_order'] ?? $index,
                ]);
            }

            return $entry->fresh(['lines', 'lines.account']);
        });
    }

    /**
     * Create a journal entry from a source document (invoice, bill, payment).
     */
    public function createFromSource(
        object $source,
        string $sourceType,
        array $lines,
        ?string $description = null
    ): JournalEntry {
        return $this->createEntry([
            'organization_id' => $source->organization_id,
            'branch_id' => $source->branch_id,
            'entry_date' => $source->created_at->toDateString(),
            'reference' => $source->number ?? $source->reference ?? null,
            'description' => $description ?? "Entry from {$sourceType}",
            'source_type' => $sourceType,
            'source_id' => $source->id,
            'currency_code' => $source->currency_code ?? 'SAR',
            'exchange_rate' => $source->exchange_rate ?? 1,
        ], $lines);
    }

    /**
     * Post a draft journal entry.
     */
    public function postEntry(JournalEntry $entry): bool
    {
        if (!$entry->isBalanced()) {
            throw new InvalidArgumentException(
                "Journal entry is not balanced. Debit: {$entry->total_debit}, Credit: {$entry->total_credit}"
            );
        }

        return $entry->post();
    }

    /**
     * Void a posted journal entry.
     */
    public function voidEntry(JournalEntry $entry, string $reason): bool
    {
        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            throw new InvalidArgumentException('Only posted entries can be voided.');
        }

        return $entry->void($reason);
    }

    /**
     * Reverse a posted journal entry.
     */
    public function reverseEntry(JournalEntry $entry, string $reason): JournalEntry
    {
        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            throw new InvalidArgumentException('Only posted entries can be reversed.');
        }

        if ($entry->reversed_by_id) {
            throw new InvalidArgumentException('Entry has already been reversed.');
        }

        $reversal = $entry->reverse($reason);

        if (!$reversal) {
            throw new InvalidArgumentException('Failed to create reversal entry.');
        }

        return $reversal;
    }

    /**
     * Create a simple two-line journal entry (debit one account, credit another).
     */
    public function createSimpleEntry(
        int $organizationId,
        int $branchId,
        int $debitAccountId,
        int $creditAccountId,
        float $amount,
        string $description,
        ?string $reference = null,
        ?string $date = null
    ): JournalEntry {
        return $this->createEntry([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'entry_date' => $date ?? now()->toDateString(),
            'reference' => $reference,
            'description' => $description,
        ], [
            ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => $amount],
        ]);
    }

    /**
     * Validate journal entry lines.
     */
    protected function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('Journal entry must have at least 2 lines.');
        }

        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($lines as $index => $line) {
            if (!isset($line['account_id'])) {
                throw new InvalidArgumentException("Line {$index}: account_id is required.");
            }

            $debit = $line['debit'] ?? 0;
            $credit = $line['credit'] ?? 0;

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException("Line {$index}: amounts cannot be negative.");
            }

            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException("Line {$index}: cannot have both debit and credit.");
            }

            if ($debit == 0 && $credit == 0) {
                throw new InvalidArgumentException("Line {$index}: must have either debit or credit.");
            }

            // Validate account exists and is postable
            $account = Account::find($line['account_id']);
            if (!$account) {
                throw new InvalidArgumentException("Line {$index}: account not found.");
            }

            if ($account->is_header) {
                throw new InvalidArgumentException("Line {$index}: cannot post to header account '{$account->name}'.");
            }

            if (!$account->is_active) {
                throw new InvalidArgumentException("Line {$index}: account '{$account->name}' is inactive.");
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if (bccomp((string) $totalDebit, (string) $totalCredit, 4) !== 0) {
            throw new InvalidArgumentException(
                "Journal entry must be balanced. Debit: {$totalDebit}, Credit: {$totalCredit}"
            );
        }
    }
}

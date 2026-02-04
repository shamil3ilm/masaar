<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BankTransaction extends Model
{
    protected $fillable = [
        'bank_account_id',
        'transaction_date',
        'reference',
        'description',
        'debit',
        'credit',
        'running_balance',
        'is_reconciled',
        'reconciled_date',
        'journal_entry_id',
        'journal_line_id',
        'source_type',
        'source_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'running_balance' => 'decimal:4',
            'is_reconciled' => 'boolean',
            'reconciled_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (BankTransaction $transaction) {
            // Update bank account balance
            $transaction->bankAccount->recalculateBalance();
        });

        static::deleted(function (BankTransaction $transaction) {
            $transaction->bankAccount->recalculateBalance();
        });
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'journal_line_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }

    /**
     * Get the net amount (positive for deposits, negative for withdrawals).
     */
    public function getNetAmount(): float
    {
        return $this->debit - $this->credit;
    }

    /**
     * Mark as reconciled.
     */
    public function reconcile(?string $date = null): void
    {
        $this->update([
            'is_reconciled' => true,
            'reconciled_date' => $date ?? now()->toDateString(),
        ]);
    }

    /**
     * Unmark as reconciled.
     */
    public function unreconcile(): void
    {
        $this->update([
            'is_reconciled' => false,
            'reconciled_date' => null,
        ]);
    }

    public function scopeUnreconciled($query)
    {
        return $query->where('is_reconciled', false);
    }

    public function scopeReconciled($query)
    {
        return $query->where('is_reconciled', true);
    }

    public function scopeForPeriod($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }
}

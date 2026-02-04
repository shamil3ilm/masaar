<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'description',
        'debit',
        'credit',
        'base_debit',
        'base_credit',
        'cost_center_id',
        'contact_id',
        'line_order',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'base_debit' => 'decimal:4',
            'base_credit' => 'decimal:4',
            'line_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (JournalEntryLine $line) {
            // Calculate base currency amounts if not set
            if ($line->base_debit === null || $line->base_debit == 0) {
                $line->base_debit = $line->debit * ($line->journalEntry->exchange_rate ?? 1);
            }
            if ($line->base_credit === null || $line->base_credit == 0) {
                $line->base_credit = $line->credit * ($line->journalEntry->exchange_rate ?? 1);
            }
        });

        static::saved(function (JournalEntryLine $line) {
            // Recalculate parent totals
            $line->journalEntry->recalculateTotals();
        });

        static::deleted(function (JournalEntryLine $line) {
            $line->journalEntry->recalculateTotals();
        });
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Check if this is a debit line.
     */
    public function isDebit(): bool
    {
        return $this->debit > 0;
    }

    /**
     * Check if this is a credit line.
     */
    public function isCredit(): bool
    {
        return $this->credit > 0;
    }

    /**
     * Get the net amount (positive for debit, negative for credit).
     */
    public function getNetAmount(): float
    {
        return $this->debit - $this->credit;
    }

    /**
     * Get the absolute amount (debit or credit, whichever is set).
     */
    public function getAmount(): float
    {
        return max($this->debit, $this->credit);
    }
}

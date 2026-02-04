<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountOpeningBalance extends Model
{
    protected $fillable = [
        'account_id',
        'fiscal_year_id',
        'debit',
        'credit',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * Get the net balance (debit - credit or credit - debit based on account type).
     */
    public function getNetBalance(): float
    {
        if ($this->account->isDebitNormal()) {
            return $this->debit - $this->credit;
        }

        return $this->credit - $this->debit;
    }
}

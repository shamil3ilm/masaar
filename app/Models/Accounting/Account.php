<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use BelongsToOrganization;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'organization_id',
        'parent_id',
        'code',
        'name',
        'description',
        'account_type',
        'sub_type',
        'currency_code',
        'is_active',
        'is_system',
        'is_header',
        'level',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'is_header' => 'boolean',
            'level' => 'integer',
        ];
    }

    // Account type constants
    public const TYPE_ASSET = 'asset';
    public const TYPE_LIABILITY = 'liability';
    public const TYPE_EQUITY = 'equity';
    public const TYPE_INCOME = 'income';
    public const TYPE_EXPENSE = 'expense';

    // Sub-type constants for each type
    public const SUBTYPE_CASH = 'cash';
    public const SUBTYPE_BANK = 'bank';
    public const SUBTYPE_RECEIVABLE = 'receivable';
    public const SUBTYPE_INVENTORY = 'inventory';
    public const SUBTYPE_FIXED_ASSET = 'fixed_asset';
    public const SUBTYPE_OTHER_ASSET = 'other_asset';
    public const SUBTYPE_PAYABLE = 'payable';
    public const SUBTYPE_CREDIT_CARD = 'credit_card';
    public const SUBTYPE_TAX_PAYABLE = 'tax_payable';
    public const SUBTYPE_OTHER_LIABILITY = 'other_liability';
    public const SUBTYPE_CAPITAL = 'capital';
    public const SUBTYPE_RETAINED_EARNINGS = 'retained_earnings';
    public const SUBTYPE_DRAWINGS = 'drawings';
    public const SUBTYPE_SALES = 'sales';
    public const SUBTYPE_OTHER_INCOME = 'other_income';
    public const SUBTYPE_COST_OF_GOODS = 'cost_of_goods';
    public const SUBTYPE_OPERATING_EXPENSE = 'operating_expense';
    public const SUBTYPE_OTHER_EXPENSE = 'other_expense';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->orderBy('code');
    }

    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    public function openingBalances(): HasMany
    {
        return $this->hasMany(AccountOpeningBalance::class, 'account_id');
    }

    /**
     * Get the full account path (e.g., "Assets > Current Assets > Cash").
     */
    public function getFullPath(): string
    {
        $parts = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($parts, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' > ', $parts);
    }

    /**
     * Check if this is a debit-normal account.
     * Assets and Expenses increase with debits.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this->account_type, [self::TYPE_ASSET, self::TYPE_EXPENSE]);
    }

    /**
     * Check if this is a credit-normal account.
     * Liabilities, Equity, and Income increase with credits.
     */
    public function isCreditNormal(): bool
    {
        return in_array($this->account_type, [self::TYPE_LIABILITY, self::TYPE_EQUITY, self::TYPE_INCOME]);
    }

    /**
     * Calculate the account balance for a given period.
     */
    public function getBalance(?int $fiscalYearId = null, ?string $asOfDate = null): float
    {
        $query = $this->journalLines()
            ->whereHas('journalEntry', function ($q) use ($fiscalYearId, $asOfDate) {
                $q->where('status', 'posted');

                if ($fiscalYearId) {
                    $q->where('fiscal_year_id', $fiscalYearId);
                }

                if ($asOfDate) {
                    $q->whereDate('entry_date', '<=', $asOfDate);
                }
            });

        $debits = (clone $query)->sum('base_debit');
        $credits = (clone $query)->sum('base_credit');

        // Calculate balance based on normal balance
        return $this->isDebitNormal()
            ? $debits - $credits
            : $credits - $debits;
    }

    /**
     * Get balance including opening balance.
     */
    public function getTotalBalance(int $fiscalYearId, ?string $asOfDate = null): float
    {
        $openingBalance = $this->openingBalances()
            ->where('fiscal_year_id', $fiscalYearId)
            ->first();

        $opening = 0;
        if ($openingBalance) {
            $opening = $this->isDebitNormal()
                ? $openingBalance->debit - $openingBalance->credit
                : $openingBalance->credit - $openingBalance->debit;
        }

        return $opening + $this->getBalance($fiscalYearId, $asOfDate);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePostable($query)
    {
        return $query->where('is_header', false)->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('account_type', $type);
    }

    public function scopeOfSubType($query, string $subType)
    {
        return $query->where('sub_type', $subType);
    }

    public function scopeRootAccounts($query)
    {
        return $query->whereNull('parent_id');
    }
}

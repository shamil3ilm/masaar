<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAuditTrail;
use App\Models\Concerns\HasUuid;
use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use HasUuid;
    use BelongsToOrganization;
    use HasAuditTrail;
    use SoftDeletes;

    public const TYPE_CURRENT = 'current';
    public const TYPE_SAVINGS = 'savings';
    public const TYPE_CREDIT_CARD = 'credit_card';
    public const TYPE_CASH = 'cash';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'bank_name',
        'account_name',
        'account_number',
        'iban',
        'swift_code',
        'branch_name',
        'branch_code',
        'currency_code',
        'account_type',
        'gl_account_id',
        'current_balance',
        'last_reconciled_date',
        'last_reconciled_balance',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:4',
            'last_reconciled_balance' => 'decimal:4',
            'last_reconciled_date' => 'date',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected $hidden = [
        'account_number', // Don't expose in API by default
        'iban',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class)->orderByDesc('transaction_date');
    }

    /**
     * Get masked account number for display.
     */
    public function getMaskedAccountNumber(): string
    {
        $length = strlen($this->account_number);
        if ($length <= 4) {
            return $this->account_number;
        }

        return str_repeat('*', $length - 4) . substr($this->account_number, -4);
    }

    /**
     * Recalculate current balance from transactions.
     */
    public function recalculateBalance(): void
    {
        $balance = $this->transactions()
            ->selectRaw('SUM(debit) - SUM(credit) as balance')
            ->value('balance') ?? 0;

        $this->update(['current_balance' => $balance]);
    }

    /**
     * Get unreconciled transactions.
     */
    public function getUnreconciledTransactions()
    {
        return $this->transactions()
            ->where('is_reconciled', false)
            ->orderBy('transaction_date')
            ->get();
    }

    /**
     * Set this as the default bank account.
     */
    public function setAsDefault(): void
    {
        static::where('organization_id', $this->organization_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('account_type', $type);
    }
}

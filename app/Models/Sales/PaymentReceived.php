<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Branch;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasStateMachine;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentReceived extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine, SoftDeletes;

    protected $table = 'payments_received';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_BOUNCED = 'bounced';

    public const METHOD_CASH = 'cash';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_CHEQUE = 'cheque';
    public const METHOD_CREDIT_CARD = 'credit_card';
    public const METHOD_ONLINE = 'online';
    public const METHOD_OTHER = 'other';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'payment_number',
        'payment_date',
        'customer_id',
        'bank_account_id',
        'payment_method',
        'amount',
        'currency_code',
        'exchange_rate',
        'base_amount',
        'reference',
        'notes',
        'status',
        'journal_entry_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'base_amount' => 'decimal:4',
        ];
    }

    protected function getStateField(): string
    {
        return 'status';
    }

    protected function getStateTransitions(): array
    {
        return [
            self::STATUS_PENDING => [self::STATUS_COMPLETED, self::STATUS_VOIDED],
            self::STATUS_COMPLETED => [self::STATUS_BOUNCED, self::STATUS_VOIDED],
            self::STATUS_BOUNCED => [self::STATUS_COMPLETED], // Can reprocess
            self::STATUS_VOIDED => [],
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_received_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get amount already allocated to invoices.
     */
    public function getAllocatedAmount(): float
    {
        return $this->allocations()->sum('amount');
    }

    /**
     * Get unallocated (available) amount.
     */
    public function getUnallocatedAmount(): float
    {
        return max(0, (float) bcsub((string) $this->amount, (string) $this->getAllocatedAmount(), 4));
    }

    /**
     * Check if payment is fully allocated.
     */
    public function isFullyAllocated(): bool
    {
        return bccomp((string) $this->getAllocatedAmount(), (string) $this->amount, 4) >= 0;
    }

    /**
     * Check if payment can be modified.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if payment is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Get payment method label.
     */
    public function getPaymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            self::METHOD_CASH => 'Cash',
            self::METHOD_BANK_TRANSFER => 'Bank Transfer',
            self::METHOD_CHEQUE => 'Cheque',
            self::METHOD_CREDIT_CARD => 'Credit Card',
            self::METHOD_ONLINE => 'Online Payment',
            self::METHOD_OTHER => 'Other',
            default => $this->payment_method,
        };
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('payment_date', [$startDate, $endDate]);
    }

    public function scopeByMethod($query, string $method)
    {
        return $query->where('payment_method', $method);
    }
}

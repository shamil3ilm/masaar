<?php

declare(strict_types=1);

namespace App\Models\Purchase;

use App\Models\Accounting\JournalEntry;
use App\Models\Branch;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasStateMachine;
use App\Models\Concerns\HasUuid;
use App\Models\Sales\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bill extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine, SoftDeletes;

    public const TYPE_STANDARD = 'standard';
    public const TYPE_DEBIT_NOTE = 'debit_note';
    public const TYPE_CREDIT_NOTE = 'credit_note';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'bill_number',
        'supplier_invoice_number',
        'bill_type',
        'purchase_order_id',
        'original_bill_id',
        'supplier_id',
        'supplier_name',
        'supplier_tax_number',
        'supplier_address',
        'bill_date',
        'due_date',
        'received_date',
        'currency_code',
        'exchange_rate',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_amount',
        'total',
        'base_total',
        'amount_paid',
        'amount_due',
        'status',
        'place_of_supply',
        'is_reverse_charge',
        'journal_entry_id',
        'notes',
        'version',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'received_date' => 'date',
            'approved_at' => 'datetime',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'base_total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'amount_due' => 'decimal:4',
            'is_reverse_charge' => 'boolean',
            'version' => 'integer',
        ];
    }

    protected function getStateField(): string
    {
        return 'status';
    }

    protected function getStateTransitions(): array
    {
        return [
            self::STATUS_DRAFT => [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_VOIDED],
            self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_VOIDED],
            self::STATUS_APPROVED => [self::STATUS_PARTIAL, self::STATUS_PAID, self::STATUS_VOIDED],
            self::STATUS_PARTIAL => [self::STATUS_PAID, self::STATUS_VOIDED],
            self::STATUS_PAID => [],
            self::STATUS_VOIDED => [],
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function originalBill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'original_bill_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('line_order');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(BillPaymentAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOverdue(): bool
    {
        if ($this->isPaid() || $this->status === self::STATUS_VOIDED) {
            return false;
        }

        return $this->due_date && $this->due_date->isPast();
    }

    public function isDebitNote(): bool
    {
        return $this->bill_type === self::TYPE_DEBIT_NOTE;
    }

    public function recalculateTotals(): void
    {
        $subtotal = $this->lines()->sum('subtotal');
        $taxAmount = $this->lines()->sum('tax_amount');

        $discountAmount = 0;
        if ($this->discount_type === 'percentage' && $this->discount_value > 0) {
            $discountAmount = bcmul((string) $subtotal, bcdiv((string) $this->discount_value, '100', 6), 4);
        } elseif ($this->discount_type === 'fixed' && $this->discount_value > 0) {
            $discountAmount = $this->discount_value;
        }

        $total = bcsub(bcadd((string) $subtotal, (string) $taxAmount, 4), (string) $discountAmount, 4);
        $baseTotal = bcmul((string) $total, (string) $this->exchange_rate, 4);

        $this->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'base_total' => $baseTotal,
            'amount_due' => bcsub((string) $total, (string) $this->amount_paid, 4),
        ]);
    }

    public function recordPayment(float $amount): void
    {
        $newAmountPaid = bcadd((string) $this->amount_paid, (string) $amount, 4);
        $newAmountDue = bcsub((string) $this->total, (string) $newAmountPaid, 4);

        $this->amount_paid = $newAmountPaid;
        $this->amount_due = max(0, (float) $newAmountDue);

        if (bccomp((string) $this->amount_due, '0', 4) <= 0) {
            $this->status = self::STATUS_PAID;
        } elseif (bccomp((string) $this->amount_paid, '0', 4) > 0) {
            $this->status = self::STATUS_PARTIAL;
        }

        $this->save();
    }

    public function getDaysPastDue(): int
    {
        if (!$this->due_date || !$this->isOverdue()) {
            return 0;
        }

        return $this->due_date->diffInDays(now());
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PARTIAL]);
    }

    public function scopeOverdue($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PARTIAL])
            ->where('due_date', '<', now());
    }

    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('bill_date', [$startDate, $endDate]);
    }
}

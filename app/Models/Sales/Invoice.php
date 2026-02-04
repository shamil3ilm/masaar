<?php

declare(strict_types=1);

namespace App\Models\Sales;

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

class Invoice extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine, SoftDeletes;

    public const TYPE_STANDARD = 'standard';
    public const TYPE_SIMPLIFIED = 'simplified';
    public const TYPE_CREDIT_NOTE = 'credit_note';
    public const TYPE_DEBIT_NOTE = 'debit_note';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_VOIDED = 'voided';

    public const COMPLIANCE_NOT_APPLICABLE = 'not_applicable';
    public const COMPLIANCE_PENDING = 'pending';
    public const COMPLIANCE_SUBMITTED = 'submitted';
    public const COMPLIANCE_CLEARED = 'cleared';
    public const COMPLIANCE_REPORTED = 'reported';
    public const COMPLIANCE_REJECTED = 'rejected';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'invoice_number',
        'invoice_type',
        'quotation_id',
        'sales_order_id',
        'original_invoice_id',
        'customer_id',
        'customer_name',
        'customer_email',
        'customer_tax_number',
        'billing_address',
        'shipping_address',
        'invoice_date',
        'due_date',
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
        'compliance_status',
        'compliance_uuid',
        'compliance_hash',
        'compliance_qr_code',
        'compliance_response',
        'compliance_submitted_at',
        'place_of_supply',
        'is_reverse_charge',
        'salesperson_id',
        'journal_entry_id',
        'notes',
        'terms_and_conditions',
        'reference',
        'version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'base_total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'amount_due' => 'decimal:4',
            'compliance_response' => 'array',
            'compliance_submitted_at' => 'datetime',
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
            self::STATUS_DRAFT => [self::STATUS_SENT, self::STATUS_VOIDED],
            self::STATUS_SENT => [self::STATUS_PARTIAL, self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_VOIDED],
            self::STATUS_PARTIAL => [self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_VOIDED],
            self::STATUS_OVERDUE => [self::STATUS_PARTIAL, self::STATUS_PAID, self::STATUS_VOIDED],
            self::STATUS_PAID => [],
            self::STATUS_VOIDED => [],
        ];
    }

    // Relationships
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'original_invoice_id')
            ->where('invoice_type', self::TYPE_CREDIT_NOTE);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_order');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Business logic
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
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

    public function isCreditNote(): bool
    {
        return $this->invoice_type === self::TYPE_CREDIT_NOTE;
    }

    public function isDebitNote(): bool
    {
        return $this->invoice_type === self::TYPE_DEBIT_NOTE;
    }

    public function requiresCompliance(): bool
    {
        return $this->compliance_status !== self::COMPLIANCE_NOT_APPLICABLE
            && !in_array($this->status, [self::STATUS_DRAFT, self::STATUS_VOIDED], true);
    }

    public function isComplianceCleared(): bool
    {
        return in_array($this->compliance_status, [
            self::COMPLIANCE_CLEARED,
            self::COMPLIANCE_REPORTED,
        ], true);
    }

    /**
     * Recalculate totals from lines.
     */
    public function recalculateTotals(): void
    {
        $subtotal = $this->lines()->sum('subtotal');
        $taxAmount = $this->lines()->sum('tax_amount');

        // Apply document-level discount
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

    /**
     * Record a payment against this invoice.
     */
    public function recordPayment(float $amount): void
    {
        $newAmountPaid = bcadd((string) $this->amount_paid, (string) $amount, 4);
        $newAmountDue = bcsub((string) $this->total, (string) $newAmountPaid, 4);

        $this->amount_paid = $newAmountPaid;
        $this->amount_due = max(0, (float) $newAmountDue);

        // Update status based on payment
        if (bccomp((string) $this->amount_due, '0', 4) <= 0) {
            $this->status = self::STATUS_PAID;
        } elseif (bccomp((string) $this->amount_paid, '0', 4) > 0) {
            $this->status = self::STATUS_PARTIAL;
        }

        $this->save();
    }

    /**
     * Get days past due.
     */
    public function getDaysPastDue(): int
    {
        if (!$this->due_date || !$this->isOverdue()) {
            return 0;
        }

        return $this->due_date->diffInDays(now());
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_PARTIAL, self::STATUS_OVERDUE]);
    }

    public function scopeOverdue($query)
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_PARTIAL])
            ->where('due_date', '<', now());
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('invoice_date', [$startDate, $endDate]);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('invoice_type', $type);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Purchase;

use App\Models\Branch;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasStateMachine;
use App\Models\Concerns\HasUuid;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_BILLED = 'billed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'order_number',
        'supplier_id',
        'supplier_name',
        'supplier_email',
        'supplier_address',
        'warehouse_id',
        'delivery_address',
        'order_date',
        'expected_delivery_date',
        'delivery_date',
        'currency_code',
        'exchange_rate',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_amount',
        'total',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'notes',
        'terms_and_conditions',
        'reference',
        'version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'delivery_date' => 'date',
            'approved_at' => 'datetime',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
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
            self::STATUS_DRAFT => [self::STATUS_SENT, self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            self::STATUS_SENT => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            self::STATUS_CONFIRMED => [self::STATUS_PARTIALLY_RECEIVED, self::STATUS_RECEIVED, self::STATUS_CANCELLED],
            self::STATUS_PARTIALLY_RECEIVED => [self::STATUS_RECEIVED, self::STATUS_BILLED],
            self::STATUS_RECEIVED => [self::STATUS_BILLED],
            self::STATUS_BILLED => [],
            self::STATUS_CANCELLED => [],
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_order');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT], true);
    }

    public function canBeReceived(): bool
    {
        return in_array($this->status, [
            self::STATUS_CONFIRMED,
            self::STATUS_PARTIALLY_RECEIVED,
        ], true);
    }

    public function canBeBilled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PARTIALLY_RECEIVED,
            self::STATUS_RECEIVED,
        ], true);
    }

    /**
     * Get receiving progress.
     */
    public function getReceivingProgress(): array
    {
        $lines = $this->lines;
        $totalQty = $lines->sum('quantity');
        $receivedQty = $lines->sum('quantity_received');
        $billedQty = $lines->sum('quantity_billed');

        return [
            'total_quantity' => $totalQty,
            'received_quantity' => $receivedQty,
            'billed_quantity' => $billedQty,
            'receiving_percentage' => $totalQty > 0 ? round(($receivedQty / $totalQty) * 100, 2) : 0,
            'billing_percentage' => $totalQty > 0 ? round(($billedQty / $totalQty) * 100, 2) : 0,
        ];
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

        $this->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ]);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [self::STATUS_BILLED, self::STATUS_CANCELLED]);
    }

    public function scopePendingReceipt($query)
    {
        return $query->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_PARTIALLY_RECEIVED]);
    }

    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }
}

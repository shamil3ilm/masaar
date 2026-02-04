<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Branch;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasStateMachine;
use App\Models\Concerns\HasUuid;
use App\Models\Inventory\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PARTIALLY_DELIVERED = 'partially_delivered';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_INVOICED = 'invoiced';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'order_number',
        'quotation_id',
        'customer_id',
        'customer_name',
        'customer_email',
        'billing_address',
        'shipping_address',
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
        'salesperson_id',
        'warehouse_id',
        'notes',
        'delivery_instructions',
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
            self::STATUS_DRAFT => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            self::STATUS_CONFIRMED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
            self::STATUS_PROCESSING => [self::STATUS_PARTIALLY_DELIVERED, self::STATUS_DELIVERED, self::STATUS_CANCELLED],
            self::STATUS_PARTIALLY_DELIVERED => [self::STATUS_DELIVERED, self::STATUS_INVOICED, self::STATUS_CANCELLED],
            self::STATUS_DELIVERED => [self::STATUS_INVOICED],
            self::STATUS_INVOICED => [],
            self::STATUS_CANCELLED => [],
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('line_order');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CONFIRMED], true);
    }

    public function canBeDelivered(): bool
    {
        return in_array($this->status, [
            self::STATUS_CONFIRMED,
            self::STATUS_PROCESSING,
            self::STATUS_PARTIALLY_DELIVERED,
        ], true);
    }

    public function canBeInvoiced(): bool
    {
        return in_array($this->status, [
            self::STATUS_PARTIALLY_DELIVERED,
            self::STATUS_DELIVERED,
        ], true);
    }

    /**
     * Get fulfillment progress.
     */
    public function getFulfillmentProgress(): array
    {
        $lines = $this->lines;
        $totalQty = $lines->sum('quantity');
        $deliveredQty = $lines->sum('quantity_delivered');
        $invoicedQty = $lines->sum('quantity_invoiced');

        return [
            'total_quantity' => $totalQty,
            'delivered_quantity' => $deliveredQty,
            'invoiced_quantity' => $invoicedQty,
            'delivery_percentage' => $totalQty > 0 ? round(($deliveredQty / $totalQty) * 100, 2) : 0,
            'invoice_percentage' => $totalQty > 0 ? round(($invoicedQty / $totalQty) * 100, 2) : 0,
        ];
    }

    /**
     * Recalculate totals from lines.
     */
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
        return $query->whereNotIn('status', [self::STATUS_INVOICED, self::STATUS_CANCELLED]);
    }

    public function scopePendingDelivery($query)
    {
        return $query->whereIn('status', [
            self::STATUS_CONFIRMED,
            self::STATUS_PROCESSING,
            self::STATUS_PARTIALLY_DELIVERED,
        ]);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }
}

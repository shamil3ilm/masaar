<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasStateMachine;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'transfer_number',
        'transfer_date',
        'expected_arrival_date',
        'from_warehouse_id',
        'to_warehouse_id',
        'notes',
        'status',
        'shipped_at',
        'shipped_by',
        'received_at',
        'received_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'expected_arrival_date' => 'date',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    protected function getStateField(): string
    {
        return 'status';
    }

    protected function getStateTransitions(): array
    {
        return [
            self::STATUS_DRAFT => [self::STATUS_IN_TRANSIT, self::STATUS_CANCELLED],
            self::STATUS_IN_TRANSIT => [self::STATUS_RECEIVED, self::STATUS_CANCELLED],
            self::STATUS_RECEIVED => [],
            self::STATUS_CANCELLED => [],
        ];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Check if transfer is editable.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if transfer can be shipped.
     */
    public function canShip(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->lines()->count() > 0;
    }

    /**
     * Check if transfer can be received.
     */
    public function canReceive(): bool
    {
        return $this->status === self::STATUS_IN_TRANSIT;
    }

    /**
     * Check if transfer is complete.
     */
    public function isComplete(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    /**
     * Get total items being transferred.
     */
    public function getTotalItemCount(): int
    {
        return $this->lines()->count();
    }

    /**
     * Get total quantity being transferred.
     */
    public function getTotalQuantity(): float
    {
        return $this->lines()->sum('quantity_sent');
    }

    /**
     * Get total value being transferred.
     */
    public function getTotalValue(): float
    {
        return $this->lines()
            ->selectRaw('SUM(quantity_sent * unit_cost) as total')
            ->value('total') ?? 0;
    }

    /**
     * Check if all items were fully received.
     */
    public function isFullyReceived(): bool
    {
        if ($this->status !== self::STATUS_RECEIVED) {
            return false;
        }

        return $this->lines()
            ->whereRaw('quantity_received < quantity_sent')
            ->count() === 0;
    }

    /**
     * Check if there's a discrepancy between sent and received.
     */
    public function hasDiscrepancy(): bool
    {
        return $this->lines()
            ->whereRaw('quantity_received != quantity_sent')
            ->exists();
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeInTransit($query)
    {
        return $query->where('status', self::STATUS_IN_TRANSIT);
    }

    public function scopeReceived($query)
    {
        return $query->where('status', self::STATUS_RECEIVED);
    }

    public function scopeFromWarehouse($query, int $warehouseId)
    {
        return $query->where('from_warehouse_id', $warehouseId);
    }

    public function scopeToWarehouse($query, int $warehouseId)
    {
        return $query->where('to_warehouse_id', $warehouseId);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_IN_TRANSIT)
            ->where('expected_arrival_date', '<', now());
    }
}

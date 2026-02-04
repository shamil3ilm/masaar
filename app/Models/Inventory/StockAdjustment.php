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

class StockAdjustment extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public const REASON_DAMAGE = 'damage';
    public const REASON_THEFT = 'theft';
    public const REASON_EXPIRY = 'expiry';
    public const REASON_COUNT_CORRECTION = 'count_correction';
    public const REASON_OPENING_BALANCE = 'opening_balance';
    public const REASON_OTHER = 'other';

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'adjustment_number',
        'adjustment_date',
        'reason',
        'notes',
        'status',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    protected function getStateField(): string
    {
        return 'status';
    }

    protected function getStateTransitions(): array
    {
        return [
            self::STATUS_DRAFT => [self::STATUS_POSTED, self::STATUS_CANCELLED],
            self::STATUS_POSTED => [],
            self::STATUS_CANCELLED => [],
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Check if adjustment is editable.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if adjustment can be posted.
     */
    public function canPost(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->lines()->count() > 0;
    }

    /**
     * Get total adjustment value.
     */
    public function getTotalValue(): float
    {
        return $this->lines()->sum('total_cost');
    }

    /**
     * Get net quantity change (can be positive or negative).
     */
    public function getNetQuantityChange(): float
    {
        return $this->lines()->sum('difference');
    }

    /**
     * Get reason label.
     */
    public function getReasonLabel(): string
    {
        return match ($this->reason) {
            self::REASON_DAMAGE => 'Damaged Goods',
            self::REASON_THEFT => 'Theft/Loss',
            self::REASON_EXPIRY => 'Expired Products',
            self::REASON_COUNT_CORRECTION => 'Stock Count Correction',
            self::REASON_OPENING_BALANCE => 'Opening Balance',
            self::REASON_OTHER => 'Other',
            default => $this->reason,
        };
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeByReason($query, string $reason)
    {
        return $query->where('reason', $reason);
    }

    public function scopeInWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }
}

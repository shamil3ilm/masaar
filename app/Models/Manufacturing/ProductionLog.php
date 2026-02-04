<?php

declare(strict_types=1);

namespace App\Models\Manufacturing;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionLog extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'work_order_id',
        'logged_at',
        'quantity_produced',
        'quantity_rejected',
        'rejection_reason',
        'quality_checked',
        'quality_checked_by',
        'quality_checked_at',
        'quality_parameters',
        'batch_number',
        'lot_number',
        'expiry_date',
        'stock_movement_id',
        'notes',
        'logged_by',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
        'quantity_produced' => 'decimal:4',
        'quantity_rejected' => 'decimal:4',
        'quality_checked' => 'boolean',
        'quality_checked_at' => 'datetime',
        'quality_parameters' => 'array',
        'expiry_date' => 'date',
    ];

    // Relationships

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function qualityCheckedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quality_checked_by');
    }

    // Scopes

    public function scopeForWorkOrder($query, int $workOrderId)
    {
        return $query->where('work_order_id', $workOrderId);
    }

    public function scopeQualityChecked($query)
    {
        return $query->where('quality_checked', true);
    }

    public function scopePendingQualityCheck($query)
    {
        return $query->where('quality_checked', false);
    }

    public function scopeWithRejections($query)
    {
        return $query->where('quantity_rejected', '>', 0);
    }

    public function scopeLoggedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('logged_at', [$startDate, $endDate]);
    }

    public function scopeForBatch($query, string $batchNumber)
    {
        return $query->where('batch_number', $batchNumber);
    }

    public function scopeForLot($query, string $lotNumber)
    {
        return $query->where('lot_number', $lotNumber);
    }

    // Helper Methods

    /**
     * Get good quantity (produced - rejected).
     */
    public function getGoodQuantity(): float
    {
        return (float) bcsub((string) $this->quantity_produced, (string) $this->quantity_rejected, 4);
    }

    /**
     * Get rejection rate.
     */
    public function getRejectionRate(): float
    {
        if ((float) $this->quantity_produced === 0.0) {
            return 0;
        }

        return round(((float) $this->quantity_rejected / (float) $this->quantity_produced) * 100, 2);
    }

    /**
     * Check if has rejections.
     */
    public function hasRejections(): bool
    {
        return (float) $this->quantity_rejected > 0;
    }

    /**
     * Check if quality checked.
     */
    public function isQualityChecked(): bool
    {
        return $this->quality_checked;
    }

    /**
     * Mark as quality checked.
     */
    public function markQualityChecked(array $parameters = [], ?int $checkedBy = null): void
    {
        $this->update([
            'quality_checked' => true,
            'quality_checked_by' => $checkedBy ?? auth()->id(),
            'quality_checked_at' => now(),
            'quality_parameters' => $parameters,
        ]);
    }

    /**
     * Check if batch tracking is used.
     */
    public function hasBatchTracking(): bool
    {
        return !empty($this->batch_number) || !empty($this->lot_number);
    }

    /**
     * Check if product is expired.
     */
    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->lt(now());
    }

    /**
     * Get days until expiry.
     */
    public function getDaysUntilExpiry(): ?int
    {
        if (!$this->expiry_date) {
            return null;
        }

        return now()->diffInDays($this->expiry_date, false);
    }
}

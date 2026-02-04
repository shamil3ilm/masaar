<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Sales\Contact;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatch extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'product_id',
        'product_variant_id',
        'warehouse_id',
        'batch_number',
        'lot_number',
        'serial_number',
        'manufacturing_date',
        'expiry_date',
        'received_date',
        'quantity',
        'reserved_quantity',
        'unit_cost',
        'status',
        'supplier_id',
        'grn_number',
        'metadata',
    ];

    protected $casts = [
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
        'received_date' => 'date',
        'quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'metadata' => 'array',
    ];

    // Statuses
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_QUARANTINE = 'quarantine';
    public const STATUS_DEPLETED = 'depleted';

    // Relationships

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    // Scopes

    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_AVAILABLE)
            ->whereRaw('quantity > reserved_quantity');
    }

    public function scopeExpired($query)
    {
        return $query->where('expiry_date', '<', today());
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereBetween('expiry_date', [today(), today()->addDays($days)]);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expiry_date')
                ->orWhere('expiry_date', '>=', today());
        });
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeInWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeFifo($query)
    {
        return $query->orderBy('expiry_date', 'asc')
            ->orderBy('received_date', 'asc')
            ->orderBy('id', 'asc');
    }

    public function scopeLifo($query)
    {
        return $query->orderBy('received_date', 'desc')
            ->orderBy('id', 'desc');
    }

    public function scopeFefo($query)
    {
        return $query->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date', 'asc')
            ->orderBy('received_date', 'asc');
    }

    // Helpers

    public function getAvailableQuantity(): string
    {
        return bcsub($this->quantity, $this->reserved_quantity, 4);
    }

    public function hasAvailableStock(): bool
    {
        return bccomp($this->getAvailableQuantity(), '0', 4) > 0;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        if (!$this->expiry_date) {
            return false;
        }

        return $this->expiry_date->isBetween(today(), today()->addDays($days));
    }

    public function getDaysUntilExpiry(): ?int
    {
        if (!$this->expiry_date) {
            return null;
        }

        return today()->diffInDays($this->expiry_date, false);
    }

    public function getShelfLifePercentage(): ?float
    {
        if (!$this->expiry_date || !$this->manufacturing_date) {
            return null;
        }

        $totalDays = $this->manufacturing_date->diffInDays($this->expiry_date);
        $remainingDays = max(0, today()->diffInDays($this->expiry_date, false));

        return $totalDays > 0 ? ($remainingDays / $totalDays) * 100 : 0;
    }

    public function reserve(string $quantity): bool
    {
        if (bccomp($quantity, $this->getAvailableQuantity(), 4) > 0) {
            return false;
        }

        $this->reserved_quantity = bcadd($this->reserved_quantity, $quantity, 4);
        return $this->save();
    }

    public function release(string $quantity): bool
    {
        if (bccomp($quantity, $this->reserved_quantity, 4) > 0) {
            return false;
        }

        $this->reserved_quantity = bcsub($this->reserved_quantity, $quantity, 4);
        return $this->save();
    }

    public function deduct(string $quantity): bool
    {
        if (bccomp($quantity, $this->quantity, 4) > 0) {
            return false;
        }

        $this->quantity = bcsub($this->quantity, $quantity, 4);

        if (bccomp($this->quantity, '0', 4) <= 0) {
            $this->status = self::STATUS_DEPLETED;
        }

        return $this->save();
    }

    public function markAsExpired(): self
    {
        $this->status = self::STATUS_EXPIRED;
        $this->save();
        return $this;
    }

    public function markAsDamaged(?string $reason = null): self
    {
        $this->status = self::STATUS_DAMAGED;
        if ($reason) {
            $metadata = $this->metadata ?? [];
            $metadata['damage_reason'] = $reason;
            $metadata['damage_date'] = now()->toDateString();
            $this->metadata = $metadata;
        }
        $this->save();
        return $this;
    }

    public function quarantine(?string $reason = null): self
    {
        $this->status = self::STATUS_QUARANTINE;
        if ($reason) {
            $metadata = $this->metadata ?? [];
            $metadata['quarantine_reason'] = $reason;
            $metadata['quarantine_date'] = now()->toDateString();
            $this->metadata = $metadata;
        }
        $this->save();
        return $this;
    }

    public static function generateBatchNumber(int $organizationId, ?string $prefix = null): string
    {
        $prefix = $prefix ?? 'BTH';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid()), 0, 4));
        return "{$prefix}-{$date}-{$random}";
    }
}

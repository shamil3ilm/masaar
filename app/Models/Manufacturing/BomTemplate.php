<?php

declare(strict_types=1);

namespace App\Models\Manufacturing;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\Core\User;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\UnitOfMeasure;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BomTemplate extends Model
{
    use BelongsToOrganization, HasUuid, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'organization_id',
        'bom_number',
        'name',
        'description',
        'product_id',
        'variant_id',
        'output_quantity',
        'output_unit_id',
        'default_warehouse_id',
        'estimated_hours',
        'estimated_labor_cost',
        'overhead_cost',
        'status',
        'effective_from',
        'effective_to',
        'version',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'output_quantity' => 'decimal:4',
        'estimated_labor_cost' => 'decimal:4',
        'overhead_cost' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'version' => 'integer',
    ];

    // Relationships

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function outputUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'output_unit_id');
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class)->orderBy('line_order');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(BomOperation::class)->orderBy('sequence');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeEffective($query, $date = null)
    {
        $date = $date ?? now();

        return $query->where(function ($q) use ($date) {
            $q->whereNull('effective_from')
                ->orWhere('effective_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $date);
        });
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    // Helper Methods

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isEffective(): bool
    {
        $now = now();

        if ($this->effective_from && $this->effective_from->gt($now)) {
            return false;
        }

        if ($this->effective_to && $this->effective_to->lt($now)) {
            return false;
        }

        return true;
    }

    public function canBeEdited(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function activate(): void
    {
        $this->update(['status' => self::STATUS_ACTIVE]);
    }

    public function deactivate(): void
    {
        $this->update(['status' => self::STATUS_INACTIVE]);
    }

    /**
     * Calculate total material cost for a given quantity.
     */
    public function calculateMaterialCost(float $quantity = 1): float
    {
        $multiplier = $quantity / (float) $this->output_quantity;
        $totalCost = 0;

        foreach ($this->lines as $line) {
            $lineQuantity = (float) $line->quantity * $multiplier;
            $wastageMultiplier = 1 + ((float) $line->wastage_percentage / 100);
            $adjustedQuantity = $lineQuantity * $wastageMultiplier;

            $totalCost = bcadd(
                (string) $totalCost,
                bcmul((string) $adjustedQuantity, (string) ($line->unit_cost ?? 0), 4),
                4
            );
        }

        return (float) $totalCost;
    }

    /**
     * Calculate total labor cost for a given quantity.
     */
    public function calculateLaborCost(float $quantity = 1): float
    {
        $multiplier = $quantity / (float) $this->output_quantity;
        $totalCost = 0;

        foreach ($this->operations as $operation) {
            $hours = ($operation->estimated_minutes / 60) * $multiplier;
            $operationCost = bcmul((string) $hours, (string) ($operation->labor_cost_per_hour ?? 0), 4);
            $totalCost = bcadd((string) $totalCost, $operationCost, 4);
        }

        return (float) $totalCost;
    }

    /**
     * Calculate total estimated cost for a given quantity.
     */
    public function calculateTotalCost(float $quantity = 1): array
    {
        $multiplier = $quantity / (float) $this->output_quantity;

        $materialCost = $this->calculateMaterialCost($quantity);
        $laborCost = $this->calculateLaborCost($quantity);
        $overheadCost = (float) bcmul((string) $this->overhead_cost, (string) $multiplier, 4);

        $totalCost = bcadd(
            bcadd((string) $materialCost, (string) $laborCost, 4),
            (string) $overheadCost,
            4
        );

        return [
            'material_cost' => $materialCost,
            'labor_cost' => $laborCost,
            'overhead_cost' => $overheadCost,
            'total_cost' => (float) $totalCost,
            'unit_cost' => $quantity > 0 ? (float) bcdiv($totalCost, (string) $quantity, 4) : 0,
        ];
    }

    /**
     * Get estimated production time in hours.
     */
    public function getEstimatedTimeHours(float $quantity = 1): float
    {
        $multiplier = $quantity / (float) $this->output_quantity;

        $totalMinutes = $this->operations->sum('estimated_minutes') * $multiplier;

        return round($totalMinutes / 60, 2);
    }

    /**
     * Check if all required materials have sufficient stock.
     */
    public function checkMaterialAvailability(float $quantity, ?int $warehouseId = null): array
    {
        $multiplier = $quantity / (float) $this->output_quantity;
        $availability = [];

        foreach ($this->lines as $line) {
            $requiredQuantity = (float) $line->quantity * $multiplier;
            $wastageMultiplier = 1 + ((float) $line->wastage_percentage / 100);
            $adjustedQuantity = $requiredQuantity * $wastageMultiplier;

            $checkWarehouseId = $warehouseId ?? $line->warehouse_id ?? $this->default_warehouse_id;

            $stockLevel = $line->product->stockLevels()
                ->when($checkWarehouseId, fn($q) => $q->where('warehouse_id', $checkWarehouseId))
                ->first();

            $availableQuantity = $stockLevel ? (float) $stockLevel->available_quantity : 0;

            $availability[] = [
                'product_id' => $line->product_id,
                'product_name' => $line->product->name,
                'required_quantity' => round($adjustedQuantity, 4),
                'available_quantity' => $availableQuantity,
                'is_sufficient' => $availableQuantity >= $adjustedQuantity,
                'shortage' => max(0, $adjustedQuantity - $availableQuantity),
                'is_critical' => $line->is_critical,
            ];
        }

        return $availability;
    }

    /**
     * Get display name.
     */
    public function getDisplayName(): string
    {
        return "{$this->bom_number} - {$this->name}";
    }
}

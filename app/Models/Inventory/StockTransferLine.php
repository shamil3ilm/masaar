<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferLine extends Model
{
    protected $fillable = [
        'stock_transfer_id',
        'product_id',
        'variant_id',
        'quantity_sent',
        'quantity_received',
        'unit_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_sent' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Get total value of this line.
     */
    public function getTotalValue(): float
    {
        return bcmul((string) $this->quantity_sent, (string) $this->unit_cost, 4);
    }

    /**
     * Get the quantity discrepancy (received - sent).
     */
    public function getDiscrepancy(): float
    {
        return bcsub((string) $this->quantity_received, (string) $this->quantity_sent, 4);
    }

    /**
     * Check if fully received.
     */
    public function isFullyReceived(): bool
    {
        return bccomp((string) $this->quantity_received, (string) $this->quantity_sent, 4) === 0;
    }

    /**
     * Check if there's shortage.
     */
    public function hasShortage(): bool
    {
        return bccomp((string) $this->quantity_received, (string) $this->quantity_sent, 4) < 0;
    }

    /**
     * Check if there's excess.
     */
    public function hasExcess(): bool
    {
        return bccomp((string) $this->quantity_received, (string) $this->quantity_sent, 4) > 0;
    }

    /**
     * Get receive percentage.
     */
    public function getReceivePercentage(): float
    {
        if (bccomp((string) $this->quantity_sent, '0', 4) === 0) {
            return 0;
        }

        return (float) bcmul(
            bcdiv((string) $this->quantity_received, (string) $this->quantity_sent, 4),
            '100',
            2
        );
    }
}

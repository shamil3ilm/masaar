<?php

declare(strict_types=1);

namespace App\Http\Resources\Manufacturing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,

            'logged_at' => $this->logged_at?->toIso8601String(),

            // Quantities
            'quantity_produced' => (float) $this->quantity_produced,
            'quantity_rejected' => (float) $this->quantity_rejected,
            'good_quantity' => $this->getGoodQuantity(),
            'rejection_rate' => $this->getRejectionRate(),
            'has_rejections' => $this->hasRejections(),
            'rejection_reason' => $this->rejection_reason,

            // Quality
            'quality_checked' => $this->quality_checked,
            'quality_checked_by' => $this->quality_checked_by,
            'quality_checker' => $this->whenLoaded('qualityCheckedBy', fn() => [
                'id' => $this->qualityCheckedBy->id,
                'name' => $this->qualityCheckedBy->name,
            ]),
            'quality_checked_at' => $this->quality_checked_at?->toIso8601String(),
            'quality_parameters' => $this->quality_parameters,

            // Batch/Lot tracking
            'batch_number' => $this->batch_number,
            'lot_number' => $this->lot_number,
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'is_expired' => $this->isExpired(),
            'days_until_expiry' => $this->getDaysUntilExpiry(),
            'has_batch_tracking' => $this->hasBatchTracking(),

            'stock_movement_id' => $this->stock_movement_id,
            'notes' => $this->notes,

            // Audit
            'logged_by' => $this->logged_by,
            'logger' => $this->whenLoaded('loggedBy', fn() => [
                'id' => $this->loggedBy->id,
                'name' => $this->loggedBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

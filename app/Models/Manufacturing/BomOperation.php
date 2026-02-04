<?php

declare(strict_types=1);

namespace App\Models\Manufacturing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomOperation extends Model
{
    protected $fillable = [
        'bom_template_id',
        'name',
        'description',
        'instructions',
        'sequence',
        'estimated_minutes',
        'labor_cost_per_hour',
        'workstation',
        'required_skills',
        'is_subcontracted',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'estimated_minutes' => 'integer',
        'labor_cost_per_hour' => 'decimal:4',
        'required_skills' => 'array',
        'is_subcontracted' => 'boolean',
    ];

    // Relationships

    public function bomTemplate(): BelongsTo
    {
        return $this->belongsTo(BomTemplate::class);
    }

    // Scopes

    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence');
    }

    public function scopeSubcontracted($query)
    {
        return $query->where('is_subcontracted', true);
    }

    public function scopeInHouse($query)
    {
        return $query->where('is_subcontracted', false);
    }

    // Helper Methods

    /**
     * Get estimated time in hours.
     */
    public function getEstimatedHours(): float
    {
        return round($this->estimated_minutes / 60, 2);
    }

    /**
     * Calculate labor cost for this operation.
     */
    public function calculateLaborCost(float $multiplier = 1): float
    {
        $hours = $this->getEstimatedHours() * $multiplier;

        return (float) bcmul((string) $hours, (string) ($this->labor_cost_per_hour ?? 0), 4);
    }

    /**
     * Check if operation requires specific skills.
     */
    public function requiresSkills(): bool
    {
        return !empty($this->required_skills);
    }
}

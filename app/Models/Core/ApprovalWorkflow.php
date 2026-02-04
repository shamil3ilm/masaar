<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalWorkflow extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'approvable_type',
        'min_amount',
        'max_amount',
        'conditions',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'min_amount' => 'decimal:4',
        'max_amount' => 'decimal:4',
        'conditions' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    // Relationships

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalWorkflowStep::class)->orderBy('sequence');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('approvable_type', $type);
    }

    public function scopeForAmount($query, float $amount)
    {
        return $query->where(function ($q) use ($amount) {
            $q->whereNull('min_amount')->orWhere('min_amount', '<=', $amount);
        })->where(function ($q) use ($amount) {
            $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount);
        });
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    // Helper Methods

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function getFirstStep(): ?ApprovalWorkflowStep
    {
        return $this->steps()->orderBy('sequence')->first();
    }

    public function getNextStep(ApprovalWorkflowStep $currentStep): ?ApprovalWorkflowStep
    {
        return $this->steps()
            ->where('sequence', '>', $currentStep->sequence)
            ->orderBy('sequence')
            ->first();
    }

    public function matchesAmount(?float $amount): bool
    {
        if ($amount === null) {
            return true;
        }

        if ($this->min_amount !== null && $amount < (float) $this->min_amount) {
            return false;
        }

        if ($this->max_amount !== null && $amount > (float) $this->max_amount) {
            return false;
        }

        return true;
    }

    public function matchesConditions(array $context): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        foreach ($this->conditions as $field => $expectedValue) {
            $actualValue = data_get($context, $field);

            if (is_array($expectedValue)) {
                if (!in_array($actualValue, $expectedValue)) {
                    return false;
                }
            } elseif ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }
}

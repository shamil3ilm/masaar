<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'probability',
        'sort_order',
        'color',
        'is_won',
        'is_lost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'sort_order' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function getOpportunityCount(): int
    {
        return $this->opportunities()->where('status', 'open')->count();
    }

    public function getTotalValue(): float
    {
        return (float) $this->opportunities()
            ->where('status', 'open')
            ->sum('amount');
    }

    public function isClosed(): bool
    {
        return $this->is_won || $this->is_lost;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeOpen($query)
    {
        return $query->where('is_won', false)->where('is_lost', false);
    }
}

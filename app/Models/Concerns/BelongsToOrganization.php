<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Core\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        // Auto-scope queries to current organization
        static::addGlobalScope('organization', function (Builder $builder): void {
            if ($user = auth()->user()) {
                $table = $builder->getModel()->getTable();
                $builder->where("{$table}.organization_id", $user->organization_id);
            }
        });

        // Auto-set organization_id on create
        static::creating(function (Model $model): void {
            if (empty($model->organization_id) && auth()->check()) {
                $model->organization_id = auth()->user()->organization_id;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId);
    }

    public function scopeWithoutOrganizationScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organization');
    }
}

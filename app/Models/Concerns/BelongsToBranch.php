<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBranch
{
    protected static function bootBelongsToBranch(): void
    {
        // Auto-set branch_id on create if not provided
        static::creating(function (Model $model): void {
            if (empty($model->branch_id) && auth()->check()) {
                // Get user's default branch
                $user = auth()->user();
                $defaultBranch = $user->branches()
                    ->wherePivot('is_default', true)
                    ->first();

                if ($defaultBranch) {
                    $model->branch_id = $defaultBranch->id;
                }
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForBranches(Builder $query, array $branchIds): Builder
    {
        return $query->whereIn('branch_id', $branchIds);
    }
}

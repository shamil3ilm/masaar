<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Branch;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'name',
        'holiday_date',
        'is_optional',
        'is_restricted',
        'applicable_to',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_optional' => 'boolean',
            'is_restricted' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isApplicableToEmployee(Employee $employee): bool
    {
        // Check branch
        if ($this->branch_id && $employee->branch_id !== $this->branch_id) {
            return false;
        }

        // Check applicability
        if ($this->applicable_to && $this->applicable_to !== 'all') {
            // Additional logic for department-specific, etc.
        }

        return true;
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('holiday_date', $date);
    }

    public function scopeInYear($query, int $year)
    {
        return $query->whereYear('holiday_date', $year);
    }

    public function scopeMandatory($query)
    {
        return $query->where('is_optional', false);
    }

    public function scopeOptional($query)
    {
        return $query->where('is_optional', true);
    }

    public function scopeForBranch($query, ?int $branchId = null)
    {
        return $query->where(function ($q) use ($branchId) {
            $q->whereNull('branch_id');
            if ($branchId) {
                $q->orWhere('branch_id', $branchId);
            }
        });
    }
}

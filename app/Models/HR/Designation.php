<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Designation extends Model
{
    use BelongsToOrganization, HasUuid, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'level',
        'min_salary',
        'max_salary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'min_salary' => 'decimal:4',
            'max_salary' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function activeEmployees(): HasMany
    {
        return $this->hasMany(Employee::class)->where('is_active', true);
    }

    public function getEmployeeCount(): int
    {
        return $this->activeEmployees()->count();
    }

    public function isSalaryInRange(float $salary): bool
    {
        if ($this->min_salary && $salary < $this->min_salary) {
            return false;
        }
        if ($this->max_salary && $salary > $this->max_salary) {
            return false;
        }
        return true;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByLevel($query, int $level)
    {
        return $query->where('level', $level);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeExperience extends Model
{
    protected $fillable = [
        'employee_id',
        'company_name',
        'designation',
        'from_date',
        'to_date',
        'responsibilities',
        'reason_for_leaving',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getDurationInMonths(): int
    {
        $endDate = $this->to_date ?? now();
        return $this->from_date->diffInMonths($endDate);
    }

    public function getDurationInYears(): float
    {
        return round($this->getDurationInMonths() / 12, 1);
    }

    public function isCurrent(): bool
    {
        return $this->to_date === null;
    }
}

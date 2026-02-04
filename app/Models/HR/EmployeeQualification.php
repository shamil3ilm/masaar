<?php

declare(strict_types=1);

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeQualification extends Model
{
    protected $fillable = [
        'employee_id',
        'qualification_type',
        'qualification_name',
        'institution',
        'specialization',
        'year_of_passing',
        'grade',
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'year_of_passing' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('qualification_type', $type);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use BelongsToOrganization;

    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_HALF_DAY = 'half_day';
    public const STATUS_ON_LEAVE = 'on_leave';
    public const STATUS_HOLIDAY = 'holiday';
    public const STATUS_WEEKEND = 'weekend';
    public const STATUS_WORK_FROM_HOME = 'work_from_home';
    public const STATUS_ON_DUTY = 'on_duty';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_BIOMETRIC = 'biometric';
    public const SOURCE_GEO_FENCE = 'geo_fence';
    public const SOURCE_IMPORT = 'import';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'attendance_date',
        'work_schedule_id',
        'check_in',
        'check_out',
        'break_start',
        'break_end',
        'working_hours',
        'overtime_hours',
        'break_hours',
        'late_minutes',
        'early_leaving_minutes',
        'status',
        'source',
        'device_id',
        'check_in_latitude',
        'check_in_longitude',
        'check_out_latitude',
        'check_out_longitude',
        'is_regularized',
        'regularization_reason',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'break_start' => 'datetime',
            'break_end' => 'datetime',
            'approved_at' => 'datetime',
            'working_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'break_hours' => 'decimal:2',
            'late_minutes' => 'integer',
            'early_leaving_minutes' => 'integer',
            'check_in_latitude' => 'decimal:8',
            'check_in_longitude' => 'decimal:8',
            'check_out_latitude' => 'decimal:8',
            'check_out_longitude' => 'decimal:8',
            'is_regularized' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function calculateWorkingHours(): float
    {
        if (!$this->check_in || !$this->check_out) {
            return 0;
        }

        $totalMinutes = $this->check_in->diffInMinutes($this->check_out);
        $breakMinutes = 0;

        if ($this->break_start && $this->break_end) {
            $breakMinutes = $this->break_start->diffInMinutes($this->break_end);
        }

        return round(($totalMinutes - $breakMinutes) / 60, 2);
    }

    public function isPresent(): bool
    {
        return in_array($this->status, [
            self::STATUS_PRESENT,
            self::STATUS_WORK_FROM_HOME,
            self::STATUS_ON_DUTY,
        ]);
    }

    public function isAbsent(): bool
    {
        return $this->status === self::STATUS_ABSENT;
    }

    public function isOnLeave(): bool
    {
        return $this->status === self::STATUS_ON_LEAVE;
    }

    public function isHalfDay(): bool
    {
        return $this->status === self::STATUS_HALF_DAY;
    }

    public function hasIncompleteEntry(): bool
    {
        return ($this->check_in && !$this->check_out) || (!$this->check_in && $this->check_out);
    }

    public function needsRegularization(): bool
    {
        return $this->hasIncompleteEntry() && !$this->is_regularized;
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('attendance_date', $date);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('attendance_date', [$startDate, $endDate]);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePresent($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PRESENT,
            self::STATUS_WORK_FROM_HOME,
            self::STATUS_ON_DUTY,
        ]);
    }

    public function scopeAbsent($query)
    {
        return $query->where('status', self::STATUS_ABSENT);
    }

    public function scopeLate($query)
    {
        return $query->where('late_minutes', '>', 0);
    }

    public function scopePendingRegularization($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('check_in')->whereNull('check_out');
            $q->orWhere(function ($q2) {
                $q2->whereNull('check_in')->whereNotNull('check_out');
            });
        })->where('is_regularized', false);
    }
}

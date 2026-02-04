<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class WorkSchedule extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'start_time',
        'end_time',
        'break_duration',
        'working_hours',
        'work_days',
        'is_flexible',
        'grace_period_minutes',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'break_duration' => 'decimal:2',
            'working_hours' => 'decimal:2',
            'work_days' => 'array',
            'is_flexible' => 'boolean',
            'grace_period_minutes' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isWorkDay(int $dayOfWeek): bool
    {
        // Day of week: 1 = Monday, 7 = Sunday
        return in_array($dayOfWeek, $this->work_days ?? [1, 2, 3, 4, 5]);
    }

    public function getWorkDaysCount(): int
    {
        return count($this->work_days ?? []);
    }

    public function isLate(\DateTime $checkInTime): bool
    {
        $scheduledStart = \Carbon\Carbon::createFromTimeString($this->start_time->format('H:i'));
        $graceEnd = $scheduledStart->copy()->addMinutes($this->grace_period_minutes);

        return \Carbon\Carbon::createFromFormat('H:i', $checkInTime->format('H:i'))->gt($graceEnd);
    }

    public function getLateMinutes(\DateTime $checkInTime): int
    {
        if (!$this->isLate($checkInTime)) {
            return 0;
        }

        $scheduledStart = \Carbon\Carbon::createFromTimeString($this->start_time->format('H:i'));
        $actualStart = \Carbon\Carbon::createFromFormat('H:i', $checkInTime->format('H:i'));

        return max(0, $actualStart->diffInMinutes($scheduledStart) - $this->grace_period_minutes);
    }

    public function isEarlyLeaving(\DateTime $checkOutTime): bool
    {
        $scheduledEnd = \Carbon\Carbon::createFromTimeString($this->end_time->format('H:i'));
        return \Carbon\Carbon::createFromFormat('H:i', $checkOutTime->format('H:i'))->lt($scheduledEnd);
    }

    public function getEarlyLeavingMinutes(\DateTime $checkOutTime): int
    {
        if (!$this->isEarlyLeaving($checkOutTime)) {
            return 0;
        }

        $scheduledEnd = \Carbon\Carbon::createFromTimeString($this->end_time->format('H:i'));
        $actualEnd = \Carbon\Carbon::createFromFormat('H:i', $checkOutTime->format('H:i'));

        return max(0, $scheduledEnd->diffInMinutes($actualEnd));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Models\HR\Attendance;
use App\Models\HR\Employee;
use App\Models\HR\Holiday;
use App\Models\HR\WorkSchedule;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /**
     * Record check-in.
     */
    public function checkIn(
        Employee $employee,
        ?\DateTime $checkInTime = null,
        string $source = Attendance::SOURCE_MANUAL,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $deviceId = null
    ): Attendance {
        $checkInTime = $checkInTime ?? now();
        $date = $checkInTime->format('Y-m-d');

        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->first();

        if ($attendance && $attendance->check_in) {
            throw new \InvalidArgumentException('Already checked in for today.');
        }

        $workSchedule = $this->getWorkSchedule($employee);

        $data = [
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'work_schedule_id' => $workSchedule?->id,
            'check_in' => $checkInTime,
            'source' => $source,
            'device_id' => $deviceId,
            'check_in_latitude' => $latitude,
            'check_in_longitude' => $longitude,
            'status' => Attendance::STATUS_PRESENT,
        ];

        if ($workSchedule) {
            $data['late_minutes'] = $workSchedule->getLateMinutes($checkInTime);
        }

        if ($attendance) {
            $attendance->update($data);
        } else {
            $attendance = Attendance::create($data);
        }

        return $attendance;
    }

    /**
     * Record check-out.
     */
    public function checkOut(
        Employee $employee,
        ?\DateTime $checkOutTime = null,
        ?float $latitude = null,
        ?float $longitude = null
    ): Attendance {
        $checkOutTime = $checkOutTime ?? now();
        $date = $checkOutTime->format('Y-m-d');

        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->first();

        if (!$attendance) {
            throw new \InvalidArgumentException('No check-in record found for today.');
        }

        if ($attendance->check_out) {
            throw new \InvalidArgumentException('Already checked out for today.');
        }

        $workSchedule = $attendance->workSchedule;

        $updateData = [
            'check_out' => $checkOutTime,
            'check_out_latitude' => $latitude,
            'check_out_longitude' => $longitude,
            'working_hours' => $attendance->calculateWorkingHours(),
        ];

        if ($workSchedule) {
            $updateData['early_leaving_minutes'] = $workSchedule->getEarlyLeavingMinutes($checkOutTime);

            // Calculate overtime
            $scheduledHours = (float) $workSchedule->working_hours;
            $actualHours = $updateData['working_hours'];
            if ($actualHours > $scheduledHours) {
                $updateData['overtime_hours'] = round($actualHours - $scheduledHours, 2);
            }
        }

        $attendance->update($updateData);

        return $attendance->fresh();
    }

    /**
     * Mark attendance manually.
     */
    public function markAttendance(
        Employee $employee,
        \DateTimeInterface $date,
        string $status,
        ?\DateTime $checkIn = null,
        ?\DateTime $checkOut = null,
        ?string $notes = null
    ): Attendance {
        $existingAttendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->first();

        $data = [
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => $status,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'notes' => $notes,
            'source' => Attendance::SOURCE_MANUAL,
        ];

        if ($checkIn && $checkOut) {
            $totalMinutes = $checkIn->diff($checkOut)->i + ($checkIn->diff($checkOut)->h * 60);
            $data['working_hours'] = round($totalMinutes / 60, 2);
        }

        if ($existingAttendance) {
            $existingAttendance->update($data);
            return $existingAttendance->fresh();
        }

        return Attendance::create($data);
    }

    /**
     * Generate attendance for a date range (mark absences, holidays, weekends).
     */
    public function generateAttendance(\DateTimeInterface $startDate, \DateTimeInterface $endDate): int
    {
        $employees = Employee::active()->get();
        $count = 0;

        $holidays = Holiday::whereBetween('holiday_date', [$startDate, $endDate])
            ->pluck('holiday_date')
            ->map(fn($d) => $d->format('Y-m-d'))
            ->toArray();

        $period = new \DatePeriod(
            new \DateTime($startDate->format('Y-m-d')),
            new \DateInterval('P1D'),
            (new \DateTime($endDate->format('Y-m-d')))->modify('+1 day')
        );

        foreach ($employees as $employee) {
            $workSchedule = $this->getWorkSchedule($employee);

            foreach ($period as $date) {
                $dateStr = $date->format('Y-m-d');

                // Skip if already has attendance
                $exists = Attendance::where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $dateStr)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Determine status
                $status = Attendance::STATUS_ABSENT;

                if (in_array($dateStr, $holidays)) {
                    $status = Attendance::STATUS_HOLIDAY;
                } elseif ($workSchedule && !$workSchedule->isWorkDay($date->format('N'))) {
                    $status = Attendance::STATUS_WEEKEND;
                }

                Attendance::create([
                    'organization_id' => $employee->organization_id,
                    'employee_id' => $employee->id,
                    'attendance_date' => $dateStr,
                    'work_schedule_id' => $workSchedule?->id,
                    'status' => $status,
                    'source' => Attendance::SOURCE_MANUAL,
                ]);

                $count++;
            }
        }

        return $count;
    }

    /**
     * Get attendance summary for an employee.
     */
    public function getEmployeeSummary(Employee $employee, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $attendances = Attendance::forEmployee($employee->id)
            ->inDateRange($startDate, $endDate)
            ->get();

        $summary = [
            'total_days' => $attendances->count(),
            'present' => $attendances->where('status', Attendance::STATUS_PRESENT)->count(),
            'absent' => $attendances->where('status', Attendance::STATUS_ABSENT)->count(),
            'half_day' => $attendances->where('status', Attendance::STATUS_HALF_DAY)->count(),
            'on_leave' => $attendances->where('status', Attendance::STATUS_ON_LEAVE)->count(),
            'holiday' => $attendances->where('status', Attendance::STATUS_HOLIDAY)->count(),
            'weekend' => $attendances->where('status', Attendance::STATUS_WEEKEND)->count(),
            'work_from_home' => $attendances->where('status', Attendance::STATUS_WORK_FROM_HOME)->count(),
            'on_duty' => $attendances->where('status', Attendance::STATUS_ON_DUTY)->count(),
            'total_working_hours' => $attendances->sum('working_hours'),
            'total_overtime_hours' => $attendances->sum('overtime_hours'),
            'total_late_minutes' => $attendances->sum('late_minutes'),
            'total_early_leaving_minutes' => $attendances->sum('early_leaving_minutes'),
            'late_count' => $attendances->where('late_minutes', '>', 0)->count(),
        ];

        $summary['working_days'] = $summary['present'] + $summary['half_day'] * 0.5 + $summary['work_from_home'] + $summary['on_duty'];

        return $summary;
    }

    /**
     * Get today's attendance status for all employees.
     */
    public function getTodayStatus(): array
    {
        $today = now()->format('Y-m-d');

        $checkedIn = Attendance::whereDate('attendance_date', $today)
            ->whereNotNull('check_in')
            ->count();

        $checkedOut = Attendance::whereDate('attendance_date', $today)
            ->whereNotNull('check_out')
            ->count();

        $onLeave = Attendance::whereDate('attendance_date', $today)
            ->where('status', Attendance::STATUS_ON_LEAVE)
            ->count();

        $totalActive = Employee::active()->count();

        return [
            'total_employees' => $totalActive,
            'checked_in' => $checkedIn,
            'checked_out' => $checkedOut,
            'on_leave' => $onLeave,
            'not_checked_in' => $totalActive - $checkedIn - $onLeave,
            'still_working' => $checkedIn - $checkedOut,
        ];
    }

    /**
     * Get work schedule for employee.
     */
    protected function getWorkSchedule(Employee $employee): ?WorkSchedule
    {
        if ($employee->work_schedule) {
            return WorkSchedule::where('code', $employee->work_schedule)->first();
        }

        return WorkSchedule::where('organization_id', $employee->organization_id)
            ->where('is_default', true)
            ->first();
    }
}

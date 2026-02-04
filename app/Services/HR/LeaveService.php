<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Models\HR\Attendance;
use App\Models\HR\Employee;
use App\Models\HR\Holiday;
use App\Models\HR\LeaveBalance;
use App\Models\HR\LeaveRequest;
use App\Models\HR\LeaveType;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    /**
     * Create a leave request.
     */
    public function createRequest(Employee $employee, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($employee, $data) {
            $leaveType = LeaveType::findOrFail($data['leave_type_id']);

            // Validate applicability
            if (!$leaveType->isApplicableToEmployee($employee)) {
                throw new \InvalidArgumentException('This leave type is not applicable to you.');
            }

            // Calculate total days
            $fromDate = new \DateTime($data['from_date']);
            $toDate = new \DateTime($data['to_date']);
            $totalDays = $this->calculateLeaveDays($employee, $fromDate, $toDate, $data['is_half_day'] ?? false);

            // Check balance
            $balance = $this->getBalance($employee, $leaveType);
            if ($totalDays > $balance) {
                throw new \InvalidArgumentException("Insufficient leave balance. Available: {$balance} days, Requested: {$totalDays} days.");
            }

            // Check for overlapping requests
            $request = new LeaveRequest([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'total_days' => $totalDays,
                'is_half_day' => $data['is_half_day'] ?? false,
                'half_day_type' => $data['half_day_type'] ?? null,
                'reason' => $data['reason'] ?? null,
                'contact_during_leave' => $data['contact_during_leave'] ?? null,
                'address_during_leave' => $data['address_during_leave'] ?? null,
                'status' => LeaveRequest::STATUS_DRAFT,
            ]);

            if ($request->overlapsWithExisting()) {
                throw new \InvalidArgumentException('You already have a leave request for these dates.');
            }

            // Check attachment requirement
            if ($leaveType->requiresAttachmentForDays($totalDays) && empty($data['attachment_path'])) {
                throw new \InvalidArgumentException('Attachment is required for leave exceeding ' . $leaveType->attachment_required_after_days . ' days.');
            }

            $request->attachment_path = $data['attachment_path'] ?? null;
            $request->save();

            return $request;
        });
    }

    /**
     * Submit a leave request for approval.
     */
    public function submit(LeaveRequest $request): LeaveRequest
    {
        if (!$request->isEditable()) {
            throw new \InvalidArgumentException('Leave request cannot be submitted.');
        }

        $request->update(['status' => LeaveRequest::STATUS_PENDING]);

        return $request->fresh();
    }

    /**
     * Approve a leave request.
     */
    public function approve(LeaveRequest $request): LeaveRequest
    {
        if (!$request->isPending()) {
            throw new \InvalidArgumentException('Only pending requests can be approved.');
        }

        return DB::transaction(function () use ($request) {
            $request->update([
                'status' => LeaveRequest::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            // Deduct from balance
            $balance = LeaveBalance::where('employee_id', $request->employee_id)
                ->where('leave_type_id', $request->leave_type_id)
                ->where('year', $request->from_date->year)
                ->first();

            if ($balance) {
                $balance->deductLeave($request->total_days);
            }

            // Mark attendance as on leave
            $this->markAttendanceAsLeave($request);

            return $request->fresh();
        });
    }

    /**
     * Reject a leave request.
     */
    public function reject(LeaveRequest $request, string $reason): LeaveRequest
    {
        if (!$request->isPending()) {
            throw new \InvalidArgumentException('Only pending requests can be rejected.');
        }

        $request->update([
            'status' => LeaveRequest::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return $request->fresh();
    }

    /**
     * Cancel a leave request.
     */
    public function cancel(LeaveRequest $request, string $reason): LeaveRequest
    {
        if (!$request->canBeCancelled()) {
            throw new \InvalidArgumentException('Leave request cannot be cancelled.');
        }

        return DB::transaction(function () use ($request, $reason) {
            $wasApproved = $request->isApproved();

            $request->update([
                'status' => LeaveRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            // Restore balance if was approved
            if ($wasApproved) {
                $balance = LeaveBalance::where('employee_id', $request->employee_id)
                    ->where('leave_type_id', $request->leave_type_id)
                    ->where('year', $request->from_date->year)
                    ->first();

                if ($balance) {
                    $balance->creditLeave($request->total_days);
                }

                // Restore attendance records
                Attendance::where('employee_id', $request->employee_id)
                    ->whereBetween('attendance_date', [$request->from_date, $request->to_date])
                    ->where('status', Attendance::STATUS_ON_LEAVE)
                    ->update(['status' => Attendance::STATUS_ABSENT]);
            }

            return $request->fresh();
        });
    }

    /**
     * Get leave balance for employee.
     */
    public function getBalance(Employee $employee, LeaveType $leaveType, ?int $year = null): float
    {
        $year = $year ?? now()->year;

        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->first();

        return $balance ? $balance->getAvailableBalance() : 0;
    }

    /**
     * Get all leave balances for employee.
     */
    public function getAllBalances(Employee $employee, ?int $year = null): \Illuminate\Support\Collection
    {
        $year = $year ?? now()->year;

        return LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->with('leaveType')
            ->get();
    }

    /**
     * Initialize leave balances for a new year.
     */
    public function initializeYearBalances(int $year): int
    {
        $employees = Employee::active()->get();
        $leaveTypes = LeaveType::active()->get();
        $count = 0;

        foreach ($employees as $employee) {
            foreach ($leaveTypes as $leaveType) {
                if (!$leaveType->isApplicableToEmployee($employee)) {
                    continue;
                }

                // Check if already exists
                $exists = LeaveBalance::where('employee_id', $employee->id)
                    ->where('leave_type_id', $leaveType->id)
                    ->where('year', $year)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Get previous year balance for carry forward
                $openingBalance = 0;
                if ($leaveType->carry_forward) {
                    $prevBalance = LeaveBalance::where('employee_id', $employee->id)
                        ->where('leave_type_id', $leaveType->id)
                        ->where('year', $year - 1)
                        ->first();

                    if ($prevBalance) {
                        $openingBalance = min(
                            $prevBalance->closing_balance,
                            $leaveType->max_carry_forward_days
                        );
                    }
                }

                // Calculate prorated quota for employees who joined mid-year
                $accrued = $leaveType->annual_quota;
                if ($leaveType->prorate_on_joining && $employee->joining_date) {
                    $joinYear = $employee->joining_date->year;
                    if ($joinYear == $year) {
                        $monthsRemaining = 12 - $employee->joining_date->month + 1;
                        $accrued = round($leaveType->annual_quota * $monthsRemaining / 12, 2);
                    }
                }

                LeaveBalance::create([
                    'organization_id' => $employee->organization_id,
                    'employee_id' => $employee->id,
                    'leave_type_id' => $leaveType->id,
                    'year' => $year,
                    'opening_balance' => $openingBalance,
                    'accrued' => $accrued,
                    'closing_balance' => $openingBalance + $accrued,
                ]);

                $count++;
            }
        }

        return $count;
    }

    /**
     * Calculate leave days excluding weekends and holidays.
     */
    public function calculateLeaveDays(
        Employee $employee,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
        bool $isHalfDay = false
    ): float {
        if ($isHalfDay) {
            return 0.5;
        }

        $holidays = Holiday::where('organization_id', $employee->organization_id)
            ->whereNull('branch_id')
            ->orWhere('branch_id', $employee->branch_id)
            ->whereBetween('holiday_date', [$fromDate, $toDate])
            ->pluck('holiday_date')
            ->map(fn($d) => $d->format('Y-m-d'))
            ->toArray();

        $period = new \DatePeriod(
            new \DateTime($fromDate->format('Y-m-d')),
            new \DateInterval('P1D'),
            (new \DateTime($toDate->format('Y-m-d')))->modify('+1 day')
        );

        $days = 0;
        foreach ($period as $date) {
            // Skip weekends (Saturday = 6, Sunday = 7)
            if (in_array($date->format('N'), ['6', '7'])) {
                continue;
            }

            // Skip holidays
            if (in_array($date->format('Y-m-d'), $holidays)) {
                continue;
            }

            $days++;
        }

        return $days;
    }

    /**
     * Mark attendance as on leave.
     */
    protected function markAttendanceAsLeave(LeaveRequest $request): void
    {
        $period = new \DatePeriod(
            new \DateTime($request->from_date->format('Y-m-d')),
            new \DateInterval('P1D'),
            (new \DateTime($request->to_date->format('Y-m-d')))->modify('+1 day')
        );

        foreach ($period as $date) {
            // Skip weekends
            if (in_array($date->format('N'), ['6', '7'])) {
                continue;
            }

            Attendance::updateOrCreate(
                [
                    'employee_id' => $request->employee_id,
                    'attendance_date' => $date->format('Y-m-d'),
                ],
                [
                    'organization_id' => $request->organization_id,
                    'status' => Attendance::STATUS_ON_LEAVE,
                    'notes' => "Leave: {$request->leaveType->name}",
                ]
            );
        }
    }

    /**
     * Get leave summary for organization.
     */
    public function getOrganizationSummary(): array
    {
        $pending = LeaveRequest::pending()->count();
        $approvedThisMonth = LeaveRequest::approved()
            ->whereBetween('from_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $onLeaveToday = LeaveRequest::approved()
            ->where('from_date', '<=', now())
            ->where('to_date', '>=', now())
            ->count();

        return [
            'pending_requests' => $pending,
            'approved_this_month' => $approvedThisMonth,
            'on_leave_today' => $onLeaveToday,
        ];
    }
}

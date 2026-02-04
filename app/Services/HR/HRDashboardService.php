<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Models\HR\Attendance;
use App\Models\HR\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\HR\Payslip;
use App\Models\HR\PayrollPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HRDashboardService
{
    protected int $organizationId;
    protected ?int $branchId = null;

    public function setContext(int $organizationId, ?int $branchId = null): self
    {
        $this->organizationId = $organizationId;
        $this->branchId = $branchId;

        return $this;
    }

    /**
     * Get all dashboard widgets data.
     */
    public function getAllWidgets(): array
    {
        return [
            'summary' => $this->getSummaryWidget(),
            'headcount_by_department' => $this->getHeadcountByDepartment(),
            'headcount_by_status' => $this->getHeadcountByStatus(),
            'attendance_today' => $this->getAttendanceTodayWidget(),
            'attendance_trend' => $this->getAttendanceTrend(),
            'leave_summary' => $this->getLeaveSummaryWidget(),
            'pending_approvals' => $this->getPendingApprovalsWidget(),
            'payroll_summary' => $this->getPayrollSummaryWidget(),
            'birthdays' => $this->getUpcomingBirthdays(),
            'anniversaries' => $this->getWorkAnniversaries(),
            'document_alerts' => $this->getDocumentExpiryAlerts(),
            'new_joiners' => $this->getNewJoiners(),
            'recent_exits' => $this->getRecentExits(),
        ];
    }

    /**
     * Get summary counts widget.
     */
    public function getSummaryWidget(): array
    {
        $query = Employee::where('organization_id', $this->organizationId);

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $totalEmployees = (clone $query)->count();
        $activeEmployees = (clone $query)->where('employment_status', 'active')->count();
        $onProbation = (clone $query)->where('employment_status', 'probation')->count();
        $onNotice = (clone $query)->where('employment_status', 'notice')->count();

        // Get counts from previous month for comparison
        $previousMonth = now()->subMonth();
        $previousActive = (clone $query)
            ->where('employment_status', 'active')
            ->where('created_at', '<=', $previousMonth->endOfMonth())
            ->whereNull('termination_date')
            ->orWhere('termination_date', '>', $previousMonth->endOfMonth())
            ->count();

        $changePercent = $previousActive > 0
            ? round((($activeEmployees - $previousActive) / $previousActive) * 100, 1)
            : 0;

        return [
            'total_employees' => $totalEmployees,
            'active_employees' => $activeEmployees,
            'on_probation' => $onProbation,
            'on_notice' => $onNotice,
            'change_from_last_month' => $changePercent,
            'change_absolute' => $activeEmployees - $previousActive,
        ];
    }

    /**
     * Get headcount by department.
     */
    public function getHeadcountByDepartment(): array
    {
        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        return $query->select('department_id', DB::raw('count(*) as count'))
            ->with('department:id,name,code')
            ->groupBy('department_id')
            ->get()
            ->map(fn ($item) => [
                'department' => $item->department->name ?? 'Unassigned',
                'department_code' => $item->department->code ?? null,
                'count' => $item->count,
            ])
            ->sortByDesc('count')
            ->values()
            ->toArray();
    }

    /**
     * Get headcount by employment status.
     */
    public function getHeadcountByStatus(): array
    {
        $query = Employee::where('organization_id', $this->organizationId);

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $statuses = $query->select('employment_status', DB::raw('count(*) as count'))
            ->groupBy('employment_status')
            ->pluck('count', 'employment_status')
            ->toArray();

        return [
            'active' => $statuses['active'] ?? 0,
            'probation' => $statuses['probation'] ?? 0,
            'notice' => $statuses['notice'] ?? 0,
            'suspended' => $statuses['suspended'] ?? 0,
            'terminated' => $statuses['terminated'] ?? 0,
        ];
    }

    /**
     * Get today's attendance widget.
     */
    public function getAttendanceTodayWidget(): array
    {
        $today = now()->toDateString();

        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $totalActive = $query->count();

        $attendanceQuery = Attendance::whereHas('employee', function ($q) {
            $q->where('organization_id', $this->organizationId);
            if ($this->branchId) {
                $q->where('branch_id', $this->branchId);
            }
        })->whereDate('attendance_date', $today);

        $present = (clone $attendanceQuery)->where('status', 'present')->count();
        $late = (clone $attendanceQuery)->where('is_late', true)->count();
        $absent = (clone $attendanceQuery)->where('status', 'absent')->count();
        $onLeave = (clone $attendanceQuery)->whereIn('status', ['on_leave', 'half_day_leave'])->count();
        $workFromHome = (clone $attendanceQuery)->where('status', 'work_from_home')->count();

        // Those who haven't checked in yet
        $notCheckedIn = $totalActive - ($present + $absent + $onLeave);

        return [
            'date' => $today,
            'total_employees' => $totalActive,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'on_leave' => $onLeave,
            'work_from_home' => $workFromHome,
            'not_checked_in' => max(0, $notCheckedIn),
            'attendance_rate' => $totalActive > 0
                ? round(($present / $totalActive) * 100, 1)
                : 0,
        ];
    }

    /**
     * Get attendance trend for last 7 days.
     */
    public function getAttendanceTrend(int $days = 7): array
    {
        $endDate = now()->toDateString();
        $startDate = now()->subDays($days - 1)->toDateString();

        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $totalActive = $query->count();

        $attendanceData = Attendance::whereHas('employee', function ($q) {
            $q->where('organization_id', $this->organizationId);
            if ($this->branchId) {
                $q->where('branch_id', $this->branchId);
            }
        })
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->where('status', 'present')
            ->select('attendance_date', DB::raw('count(*) as present_count'))
            ->groupBy('attendance_date')
            ->pluck('present_count', 'attendance_date')
            ->toArray();

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $present = $attendanceData[$date] ?? 0;
            $trend[] = [
                'date' => $date,
                'day' => now()->subDays($i)->format('D'),
                'present' => $present,
                'total' => $totalActive,
                'rate' => $totalActive > 0 ? round(($present / $totalActive) * 100, 1) : 0,
            ];
        }

        return $trend;
    }

    /**
     * Get leave summary widget.
     */
    public function getLeaveSummaryWidget(): array
    {
        $query = LeaveRequest::whereHas('employee', function ($q) {
            $q->where('organization_id', $this->organizationId);
            if ($this->branchId) {
                $q->where('branch_id', $this->branchId);
            }
        });

        $today = now()->toDateString();
        $thisMonth = now()->format('Y-m');

        return [
            'pending_approval' => (clone $query)->where('status', 'pending')->count(),
            'on_leave_today' => (clone $query)
                ->where('status', 'approved')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->count(),
            'approved_this_month' => (clone $query)
                ->where('status', 'approved')
                ->whereRaw("DATE_FORMAT(start_date, '%Y-%m') = ?", [$thisMonth])
                ->count(),
            'rejected_this_month' => (clone $query)
                ->where('status', 'rejected')
                ->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$thisMonth])
                ->count(),
        ];
    }

    /**
     * Get pending approvals widget.
     */
    public function getPendingApprovalsWidget(): array
    {
        $query = LeaveRequest::whereHas('employee', function ($q) {
            $q->where('organization_id', $this->organizationId);
            if ($this->branchId) {
                $q->where('branch_id', $this->branchId);
            }
        })->where('status', 'pending');

        $pendingLeaves = $query->with(['employee:id,first_name,last_name,employee_number', 'leaveType:id,name,code'])
            ->orderBy('start_date')
            ->limit(5)
            ->get()
            ->map(fn ($lr) => [
                'id' => $lr->id,
                'employee' => $lr->employee->first_name.' '.$lr->employee->last_name,
                'employee_number' => $lr->employee->employee_number,
                'leave_type' => $lr->leaveType->name ?? 'N/A',
                'start_date' => $lr->start_date->format('Y-m-d'),
                'end_date' => $lr->end_date->format('Y-m-d'),
                'days' => $lr->total_days,
                'submitted_at' => $lr->created_at->format('Y-m-d H:i'),
            ]);

        return [
            'total_pending' => $query->count(),
            'items' => $pendingLeaves->toArray(),
        ];
    }

    /**
     * Get payroll summary widget.
     */
    public function getPayrollSummaryWidget(): array
    {
        // Get current/latest payroll period
        $currentPeriod = PayrollPeriod::where('organization_id', $this->organizationId)
            ->orderByDesc('start_date')
            ->first();

        if (! $currentPeriod) {
            return [
                'current_period' => null,
                'total_payroll' => 0,
                'employees_paid' => 0,
                'pending_approval' => 0,
            ];
        }

        $payslipQuery = Payslip::where('payroll_period_id', $currentPeriod->id);

        if ($this->branchId) {
            $payslipQuery->whereHas('employee', fn ($q) => $q->where('branch_id', $this->branchId));
        }

        $totalGross = (clone $payslipQuery)->sum('gross_earnings');
        $totalNet = (clone $payslipQuery)->sum('net_salary');
        $totalDeductions = (clone $payslipQuery)->sum('total_deductions');
        $employeesPaid = (clone $payslipQuery)->where('status', 'paid')->count();
        $pendingApproval = (clone $payslipQuery)->where('status', 'draft')->count();
        $totalPayslips = (clone $payslipQuery)->count();

        // Get last 6 months payroll trend
        $payrollTrend = PayrollPeriod::where('organization_id', $this->organizationId)
            ->orderByDesc('start_date')
            ->limit(6)
            ->get()
            ->map(function ($period) {
                $query = Payslip::where('payroll_period_id', $period->id);
                if ($this->branchId) {
                    $query->whereHas('employee', fn ($q) => $q->where('branch_id', $this->branchId));
                }

                return [
                    'period' => $period->name,
                    'month' => $period->start_date->format('M Y'),
                    'gross' => $query->sum('gross_earnings'),
                    'net' => $query->sum('net_salary'),
                    'employees' => $query->count(),
                ];
            })
            ->reverse()
            ->values();

        return [
            'current_period' => [
                'id' => $currentPeriod->id,
                'name' => $currentPeriod->name,
                'start_date' => $currentPeriod->start_date->format('Y-m-d'),
                'end_date' => $currentPeriod->end_date->format('Y-m-d'),
                'status' => $currentPeriod->status,
            ],
            'total_gross' => round($totalGross, 2),
            'total_net' => round($totalNet, 2),
            'total_deductions' => round($totalDeductions, 2),
            'total_payslips' => $totalPayslips,
            'employees_paid' => $employeesPaid,
            'pending_approval' => $pendingApproval,
            'trend' => $payrollTrend->toArray(),
        ];
    }

    /**
     * Get upcoming birthdays.
     */
    public function getUpcomingBirthdays(int $days = 30): array
    {
        $today = now();
        $endDate = now()->addDays($days);

        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active')
            ->whereNotNull('date_of_birth');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        // Get employees with birthdays in the next X days
        $employees = $query->get()
            ->filter(function ($employee) use ($today, $endDate) {
                $birthday = $employee->date_of_birth->setYear($today->year);

                // If birthday this year has passed, check next year
                if ($birthday->isPast()) {
                    $birthday = $birthday->addYear();
                }

                return $birthday->between($today, $endDate);
            })
            ->map(function ($employee) use ($today) {
                $birthday = $employee->date_of_birth->setYear($today->year);
                if ($birthday->isPast()) {
                    $birthday = $birthday->addYear();
                }

                return [
                    'id' => $employee->id,
                    'name' => $employee->first_name.' '.$employee->last_name,
                    'employee_number' => $employee->employee_number,
                    'department' => $employee->department->name ?? 'N/A',
                    'date' => $birthday->format('Y-m-d'),
                    'day' => $birthday->format('D, M j'),
                    'days_away' => $today->diffInDays($birthday),
                    'is_today' => $birthday->isToday(),
                ];
            })
            ->sortBy('days_away')
            ->values();

        return [
            'upcoming_count' => $employees->count(),
            'today_count' => $employees->where('is_today', true)->count(),
            'items' => $employees->take(10)->toArray(),
        ];
    }

    /**
     * Get work anniversaries.
     */
    public function getWorkAnniversaries(int $days = 30): array
    {
        $today = now();
        $endDate = now()->addDays($days);

        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active')
            ->whereNotNull('date_of_joining');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $employees = $query->get()
            ->filter(function ($employee) use ($today, $endDate) {
                $anniversary = $employee->date_of_joining->setYear($today->year);

                if ($anniversary->isPast()) {
                    $anniversary = $anniversary->addYear();
                }

                return $anniversary->between($today, $endDate);
            })
            ->map(function ($employee) use ($today) {
                $anniversary = $employee->date_of_joining->setYear($today->year);
                if ($anniversary->isPast()) {
                    $anniversary = $anniversary->addYear();
                }

                $years = $today->year - $employee->date_of_joining->year;
                if ($anniversary->gt($today)) {
                    $years++;
                }

                return [
                    'id' => $employee->id,
                    'name' => $employee->first_name.' '.$employee->last_name,
                    'employee_number' => $employee->employee_number,
                    'department' => $employee->department->name ?? 'N/A',
                    'date' => $anniversary->format('Y-m-d'),
                    'day' => $anniversary->format('D, M j'),
                    'years' => $years,
                    'days_away' => $today->diffInDays($anniversary),
                    'is_today' => $anniversary->isToday(),
                ];
            })
            ->sortBy('days_away')
            ->values();

        return [
            'upcoming_count' => $employees->count(),
            'today_count' => $employees->where('is_today', true)->count(),
            'items' => $employees->take(10)->toArray(),
        ];
    }

    /**
     * Get document expiry alerts.
     */
    public function getDocumentExpiryAlerts(int $days = 30): array
    {
        $today = now()->toDateString();
        $alertDate = now()->addDays($days)->toDateString();

        $documents = DB::table('employee_documents')
            ->join('employees', 'employee_documents.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $this->organizationId)
            ->where('employees.employment_status', 'active')
            ->whereNotNull('employee_documents.expiry_date')
            ->where('employee_documents.expiry_date', '<=', $alertDate)
            ->when($this->branchId, fn ($q) => $q->where('employees.branch_id', $this->branchId))
            ->select([
                'employee_documents.id',
                'employee_documents.document_type',
                'employee_documents.document_number',
                'employee_documents.expiry_date',
                'employees.id as employee_id',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_number',
            ])
            ->orderBy('employee_documents.expiry_date')
            ->get()
            ->map(function ($doc) use ($today) {
                $expiryDate = Carbon::parse($doc->expiry_date);
                $daysUntilExpiry = now()->diffInDays($expiryDate, false);

                return [
                    'id' => $doc->id,
                    'document_type' => $doc->document_type,
                    'document_number' => $doc->document_number,
                    'expiry_date' => $doc->expiry_date,
                    'employee_id' => $doc->employee_id,
                    'employee_name' => $doc->first_name.' '.$doc->last_name,
                    'employee_number' => $doc->employee_number,
                    'days_until_expiry' => $daysUntilExpiry,
                    'is_expired' => $daysUntilExpiry < 0,
                    'severity' => $this->getExpirySeverity($daysUntilExpiry),
                ];
            });

        return [
            'total_alerts' => $documents->count(),
            'expired' => $documents->where('is_expired', true)->count(),
            'expiring_soon' => $documents->where('is_expired', false)->count(),
            'items' => $documents->take(10)->toArray(),
        ];
    }

    /**
     * Get new joiners.
     */
    public function getNewJoiners(int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $query = Employee::where('organization_id', $this->organizationId)
            ->where('date_of_joining', '>=', $startDate)
            ->with(['department:id,name', 'designation:id,name']);

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $joiners = $query->orderByDesc('date_of_joining')
            ->limit(10)
            ->get()
            ->map(fn ($emp) => [
                'id' => $emp->id,
                'name' => $emp->first_name.' '.$emp->last_name,
                'employee_number' => $emp->employee_number,
                'department' => $emp->department->name ?? 'N/A',
                'designation' => $emp->designation->name ?? 'N/A',
                'date_of_joining' => $emp->date_of_joining->format('Y-m-d'),
                'days_since_joining' => $emp->date_of_joining->diffInDays(now()),
            ]);

        return [
            'count' => $query->count(),
            'items' => $joiners->toArray(),
        ];
    }

    /**
     * Get recent exits.
     */
    public function getRecentExits(int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'terminated')
            ->where('termination_date', '>=', $startDate)
            ->with(['department:id,name', 'designation:id,name']);

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $exits = $query->orderByDesc('termination_date')
            ->limit(10)
            ->get()
            ->map(fn ($emp) => [
                'id' => $emp->id,
                'name' => $emp->first_name.' '.$emp->last_name,
                'employee_number' => $emp->employee_number,
                'department' => $emp->department->name ?? 'N/A',
                'designation' => $emp->designation->name ?? 'N/A',
                'termination_date' => $emp->termination_date->format('Y-m-d'),
                'termination_reason' => $emp->termination_reason ?? 'N/A',
            ]);

        return [
            'count' => $query->count(),
            'items' => $exits->toArray(),
        ];
    }

    /**
     * Get gender distribution.
     */
    public function getGenderDistribution(): array
    {
        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        return $query->select('gender', DB::raw('count(*) as count'))
            ->whereNotNull('gender')
            ->groupBy('gender')
            ->pluck('count', 'gender')
            ->toArray();
    }

    /**
     * Get age distribution.
     */
    public function getAgeDistribution(): array
    {
        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active')
            ->whereNotNull('date_of_birth');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $employees = $query->get();
        $distribution = [
            '18-25' => 0,
            '26-35' => 0,
            '36-45' => 0,
            '46-55' => 0,
            '55+' => 0,
        ];

        foreach ($employees as $emp) {
            $age = $emp->date_of_birth->age;
            if ($age <= 25) {
                $distribution['18-25']++;
            } elseif ($age <= 35) {
                $distribution['26-35']++;
            } elseif ($age <= 45) {
                $distribution['36-45']++;
            } elseif ($age <= 55) {
                $distribution['46-55']++;
            } else {
                $distribution['55+']++;
            }
        }

        return $distribution;
    }

    /**
     * Get tenure distribution.
     */
    public function getTenureDistribution(): array
    {
        $query = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active')
            ->whereNotNull('date_of_joining');

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        $employees = $query->get();
        $distribution = [
            '< 1 year' => 0,
            '1-2 years' => 0,
            '2-5 years' => 0,
            '5-10 years' => 0,
            '10+ years' => 0,
        ];

        foreach ($employees as $emp) {
            $years = $emp->date_of_joining->diffInYears(now());
            if ($years < 1) {
                $distribution['< 1 year']++;
            } elseif ($years < 2) {
                $distribution['1-2 years']++;
            } elseif ($years < 5) {
                $distribution['2-5 years']++;
            } elseif ($years < 10) {
                $distribution['5-10 years']++;
            } else {
                $distribution['10+ years']++;
            }
        }

        return $distribution;
    }

    /**
     * Determine expiry severity.
     */
    protected function getExpirySeverity(int $daysUntilExpiry): string
    {
        if ($daysUntilExpiry < 0) {
            return 'critical';
        }
        if ($daysUntilExpiry <= 7) {
            return 'high';
        }
        if ($daysUntilExpiry <= 30) {
            return 'medium';
        }

        return 'low';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Models\HR\Attendance;
use App\Models\HR\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\HR\Payslip;
use Illuminate\Support\Facades\DB;

class HRReportService
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
     * Generate Headcount Report.
     */
    public function generateHeadcountReport(string $asOfDate, ?int $departmentId = null): array
    {
        $query = Employee::where('organization_id', $this->organizationId)
            ->where('date_of_joining', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate) {
                $q->whereNull('date_of_exit')
                    ->orWhere('date_of_exit', '>', $asOfDate);
            });

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $employees = $query->with(['department', 'designation'])->get();

        // Group by department
        $byDepartment = $employees->groupBy('department_id')->map(function ($group) {
            $dept = $group->first()->department;
            return [
                'department_id' => $dept?->id,
                'department_name' => $dept?->name ?? 'Unassigned',
                'count' => $group->count(),
                'male' => $group->where('gender', 'male')->count(),
                'female' => $group->where('gender', 'female')->count(),
                'permanent' => $group->where('employment_type', 'permanent')->count(),
                'contract' => $group->where('employment_type', 'contract')->count(),
                'probation' => $group->where('employment_type', 'probation')->count(),
            ];
        })->values()->toArray();

        // Group by designation
        $byDesignation = $employees->groupBy('designation_id')->map(function ($group) {
            $desig = $group->first()->designation;
            return [
                'designation_id' => $desig?->id,
                'designation_name' => $desig?->name ?? 'Unassigned',
                'count' => $group->count(),
            ];
        })->sortByDesc('count')->values()->take(10)->toArray();

        // Group by tenure
        $byTenure = [
            '0_1_year' => $employees->filter(fn($e) => $e->getTenureInYears() < 1)->count(),
            '1_3_years' => $employees->filter(fn($e) => $e->getTenureInYears() >= 1 && $e->getTenureInYears() < 3)->count(),
            '3_5_years' => $employees->filter(fn($e) => $e->getTenureInYears() >= 3 && $e->getTenureInYears() < 5)->count(),
            '5_10_years' => $employees->filter(fn($e) => $e->getTenureInYears() >= 5 && $e->getTenureInYears() < 10)->count(),
            '10_plus_years' => $employees->filter(fn($e) => $e->getTenureInYears() >= 10)->count(),
        ];

        // Group by age
        $byAge = [
            'under_25' => $employees->filter(fn($e) => $e->getAge() < 25)->count(),
            '25_35' => $employees->filter(fn($e) => $e->getAge() >= 25 && $e->getAge() < 35)->count(),
            '35_45' => $employees->filter(fn($e) => $e->getAge() >= 35 && $e->getAge() < 45)->count(),
            '45_55' => $employees->filter(fn($e) => $e->getAge() >= 45 && $e->getAge() < 55)->count(),
            '55_plus' => $employees->filter(fn($e) => $e->getAge() >= 55)->count(),
        ];

        // Nationality distribution
        $byNationality = $employees->groupBy('nationality')
            ->map(fn($group, $nat) => ['nationality' => $nat ?: 'Unknown', 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->take(10)
            ->toArray();

        return [
            'report_type' => 'headcount',
            'as_of_date' => $asOfDate,
            'summary' => [
                'total_headcount' => $employees->count(),
                'male' => $employees->where('gender', 'male')->count(),
                'female' => $employees->where('gender', 'female')->count(),
                'permanent' => $employees->where('employment_type', 'permanent')->count(),
                'contract' => $employees->where('employment_type', 'contract')->count(),
                'probation' => $employees->where('employment_type', 'probation')->count(),
                'average_tenure_years' => round($employees->avg(fn($e) => $e->getTenureInYears()), 1),
                'average_age' => round($employees->avg(fn($e) => $e->getAge()), 1),
            ],
            'by_department' => $byDepartment,
            'by_designation' => $byDesignation,
            'by_tenure' => $byTenure,
            'by_age' => $byAge,
            'by_nationality' => $byNationality,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Employee Turnover Report.
     */
    public function generateTurnoverReport(string $startDate, string $endDate): array
    {
        // Starting headcount
        $startingCount = Employee::where('organization_id', $this->organizationId)
            ->where('date_of_joining', '<', $startDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('date_of_exit')
                    ->orWhere('date_of_exit', '>=', $startDate);
            })
            ->when($this->branchId, fn($q) => $q->where('branch_id', $this->branchId))
            ->count();

        // New hires
        $newHires = Employee::where('organization_id', $this->organizationId)
            ->whereBetween('date_of_joining', [$startDate, $endDate])
            ->when($this->branchId, fn($q) => $q->where('branch_id', $this->branchId))
            ->with('department')
            ->get();

        // Separations
        $separations = Employee::where('organization_id', $this->organizationId)
            ->whereBetween('date_of_exit', [$startDate, $endDate])
            ->when($this->branchId, fn($q) => $q->where('branch_id', $this->branchId))
            ->with('department')
            ->get();

        // Ending headcount
        $endingCount = Employee::where('organization_id', $this->organizationId)
            ->where('date_of_joining', '<=', $endDate)
            ->where(function ($q) use ($endDate) {
                $q->whereNull('date_of_exit')
                    ->orWhere('date_of_exit', '>', $endDate);
            })
            ->when($this->branchId, fn($q) => $q->where('branch_id', $this->branchId))
            ->count();

        // Calculate rates
        $averageHeadcount = ($startingCount + $endingCount) / 2;
        $turnoverRate = $averageHeadcount > 0 ? ($separations->count() / $averageHeadcount) * 100 : 0;
        $attritionRate = $averageHeadcount > 0
            ? ($separations->where('exit_type', 'resignation')->count() / $averageHeadcount) * 100
            : 0;

        // New hires by department
        $hiresByDept = $newHires->groupBy('department_id')->map(function ($group) {
            return [
                'department' => $group->first()->department?->name ?? 'Unassigned',
                'count' => $group->count(),
            ];
        })->sortByDesc('count')->values()->toArray();

        // Separations by reason
        $separationsByReason = $separations->groupBy('exit_type')->map(function ($group, $type) {
            return [
                'reason' => ucfirst(str_replace('_', ' ', $type ?: 'Unknown')),
                'count' => $group->count(),
            ];
        })->values()->toArray();

        // Separations by tenure
        $separationsByTenure = [
            '0_6_months' => $separations->filter(fn($e) => $e->getTenureInMonths() < 6)->count(),
            '6_12_months' => $separations->filter(fn($e) => $e->getTenureInMonths() >= 6 && $e->getTenureInMonths() < 12)->count(),
            '1_2_years' => $separations->filter(fn($e) => $e->getTenureInYears() >= 1 && $e->getTenureInYears() < 2)->count(),
            '2_5_years' => $separations->filter(fn($e) => $e->getTenureInYears() >= 2 && $e->getTenureInYears() < 5)->count(),
            '5_plus_years' => $separations->filter(fn($e) => $e->getTenureInYears() >= 5)->count(),
        ];

        return [
            'report_type' => 'turnover',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'summary' => [
                'starting_headcount' => $startingCount,
                'new_hires' => $newHires->count(),
                'separations' => $separations->count(),
                'ending_headcount' => $endingCount,
                'net_change' => $newHires->count() - $separations->count(),
                'turnover_rate' => round($turnoverRate, 2),
                'attrition_rate' => round($attritionRate, 2),
                'retention_rate' => round(100 - $turnoverRate, 2),
            ],
            'new_hires_by_department' => $hiresByDept,
            'separations_by_reason' => $separationsByReason,
            'separations_by_tenure' => $separationsByTenure,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Attendance Summary Report.
     */
    public function generateAttendanceReport(string $startDate, string $endDate, ?int $departmentId = null): array
    {
        $query = Attendance::where('organization_id', $this->organizationId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->with('employee.department');

        if ($this->branchId) {
            $query->whereHas('employee', fn($q) => $q->where('branch_id', $this->branchId));
        }

        if ($departmentId) {
            $query->whereHas('employee', fn($q) => $q->where('department_id', $departmentId));
        }

        $attendance = $query->get();

        // Overall summary
        $summary = [
            'total_records' => $attendance->count(),
            'present' => $attendance->whereIn('status', ['present', 'late', 'early_leave'])->count(),
            'absent' => $attendance->where('status', 'absent')->count(),
            'late' => $attendance->where('status', 'late')->count(),
            'on_leave' => $attendance->where('status', 'on_leave')->count(),
            'half_day' => $attendance->where('status', 'half_day')->count(),
            'total_working_hours' => round($attendance->sum('total_working_hours'), 2),
            'total_overtime_hours' => round($attendance->sum('overtime_hours'), 2),
            'average_working_hours' => round($attendance->avg('total_working_hours'), 2),
        ];

        // By department
        $byDepartment = $attendance->groupBy('employee.department_id')->map(function ($group) {
            $dept = $group->first()->employee?->department;
            $present = $group->whereIn('status', ['present', 'late', 'early_leave'])->count();
            $total = $group->count();

            return [
                'department' => $dept?->name ?? 'Unassigned',
                'total_records' => $total,
                'present' => $present,
                'absent' => $group->where('status', 'absent')->count(),
                'late' => $group->where('status', 'late')->count(),
                'attendance_rate' => $total > 0 ? round(($present / $total) * 100, 2) : 0,
            ];
        })->sortByDesc('attendance_rate')->values()->toArray();

        // By day of week
        $byDayOfWeek = $attendance->groupBy(fn($a) => $a->attendance_date->format('l'))->map(function ($group, $day) {
            $present = $group->whereIn('status', ['present', 'late', 'early_leave'])->count();
            return [
                'day' => $day,
                'total' => $group->count(),
                'present' => $present,
                'absent' => $group->where('status', 'absent')->count(),
            ];
        })->values()->toArray();

        // Late arrivals analysis
        $lateArrivals = $attendance->where('status', 'late');
        $lateByEmployee = $lateArrivals->groupBy('employee_id')
            ->map(fn($group) => [
                'employee' => $group->first()->employee?->getDisplayName(),
                'late_count' => $group->count(),
            ])
            ->sortByDesc('late_count')
            ->take(10)
            ->values()
            ->toArray();

        return [
            'report_type' => 'attendance',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'summary' => $summary,
            'by_department' => $byDepartment,
            'by_day_of_week' => $byDayOfWeek,
            'top_late_arrivals' => $lateByEmployee,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Leave Analysis Report.
     */
    public function generateLeaveReport(string $startDate, string $endDate, ?int $departmentId = null): array
    {
        $query = LeaveRequest::where('organization_id', $this->organizationId)
            ->whereBetween('start_date', [$startDate, $endDate])
            ->with(['employee.department', 'leaveType']);

        if ($this->branchId) {
            $query->whereHas('employee', fn($q) => $q->where('branch_id', $this->branchId));
        }

        if ($departmentId) {
            $query->whereHas('employee', fn($q) => $q->where('department_id', $departmentId));
        }

        $leaves = $query->get();

        // Summary
        $summary = [
            'total_requests' => $leaves->count(),
            'approved' => $leaves->where('status', 'approved')->count(),
            'pending' => $leaves->where('status', 'pending')->count(),
            'rejected' => $leaves->where('status', 'rejected')->count(),
            'cancelled' => $leaves->where('status', 'cancelled')->count(),
            'total_days_requested' => $leaves->sum('total_days'),
            'total_days_approved' => $leaves->where('status', 'approved')->sum('total_days'),
        ];

        // By leave type
        $byLeaveType = $leaves->groupBy('leave_type_id')->map(function ($group) {
            $type = $group->first()->leaveType;
            return [
                'leave_type' => $type?->name ?? 'Unknown',
                'requests' => $group->count(),
                'days' => $group->sum('total_days'),
                'approved' => $group->where('status', 'approved')->count(),
            ];
        })->sortByDesc('requests')->values()->toArray();

        // By department
        $byDepartment = $leaves->groupBy('employee.department_id')->map(function ($group) {
            $dept = $group->first()->employee?->department;
            return [
                'department' => $dept?->name ?? 'Unassigned',
                'requests' => $group->count(),
                'days' => $group->sum('total_days'),
            ];
        })->sortByDesc('requests')->values()->toArray();

        // By month
        $byMonth = $leaves->groupBy(fn($l) => $l->start_date->format('Y-m'))->map(function ($group, $month) {
            return [
                'month' => $month,
                'requests' => $group->count(),
                'days' => $group->sum('total_days'),
            ];
        })->sortBy('month')->values()->toArray();

        // Employees with most leaves
        $topLeaveTakers = $leaves->where('status', 'approved')
            ->groupBy('employee_id')
            ->map(fn($group) => [
                'employee' => $group->first()->employee?->getDisplayName(),
                'leaves' => $group->count(),
                'days' => $group->sum('total_days'),
            ])
            ->sortByDesc('days')
            ->take(10)
            ->values()
            ->toArray();

        return [
            'report_type' => 'leave_analysis',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'summary' => $summary,
            'by_leave_type' => $byLeaveType,
            'by_department' => $byDepartment,
            'by_month' => $byMonth,
            'top_leave_takers' => $topLeaveTakers,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate Payroll Summary Report.
     */
    public function generatePayrollReport(string $startDate, string $endDate): array
    {
        $query = Payslip::where('organization_id', $this->organizationId)
            ->whereHas('payrollPeriod', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate]);
            })
            ->with(['employee.department', 'items.salaryComponent', 'payrollPeriod']);

        if ($this->branchId) {
            $query->whereHas('employee', fn($q) => $q->where('branch_id', $this->branchId));
        }

        $payslips = $query->get();

        // Summary
        $summary = [
            'total_payslips' => $payslips->count(),
            'total_gross' => $payslips->sum('gross_earnings'),
            'total_deductions' => $payslips->sum('total_deductions'),
            'total_net' => $payslips->sum('net_salary'),
            'average_gross' => round($payslips->avg('gross_earnings'), 2),
            'average_net' => round($payslips->avg('net_salary'), 2),
            'paid_count' => $payslips->where('status', 'paid')->count(),
            'pending_count' => $payslips->whereIn('status', ['draft', 'pending', 'approved'])->count(),
        ];

        // By department
        $byDepartment = $payslips->groupBy('employee.department_id')->map(function ($group) {
            $dept = $group->first()->employee?->department;
            return [
                'department' => $dept?->name ?? 'Unassigned',
                'employees' => $group->count(),
                'gross' => $group->sum('gross_earnings'),
                'deductions' => $group->sum('total_deductions'),
                'net' => $group->sum('net_salary'),
            ];
        })->sortByDesc('gross')->values()->toArray();

        // By component
        $componentTotals = [];
        foreach ($payslips as $payslip) {
            foreach ($payslip->items as $item) {
                $code = $item->salaryComponent?->code ?? $item->name;
                if (!isset($componentTotals[$code])) {
                    $componentTotals[$code] = [
                        'name' => $item->name,
                        'type' => $item->type,
                        'total' => 0,
                        'count' => 0,
                    ];
                }
                $componentTotals[$code]['total'] += $item->amount;
                $componentTotals[$code]['count']++;
            }
        }

        $byComponent = collect($componentTotals)
            ->sortByDesc('total')
            ->values()
            ->toArray();

        // Monthly trend
        $byMonth = $payslips->groupBy(fn($p) => $p->payrollPeriod?->start_date?->format('Y-m'))->map(function ($group, $month) {
            return [
                'month' => $month,
                'employees' => $group->count(),
                'gross' => $group->sum('gross_earnings'),
                'net' => $group->sum('net_salary'),
            ];
        })->sortBy('month')->values()->toArray();

        return [
            'report_type' => 'payroll_summary',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'currency' => $payslips->first()?->currency_code ?? 'USD',
            'summary' => $summary,
            'by_department' => $byDepartment,
            'by_component' => $byComponent,
            'monthly_trend' => $byMonth,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate HR Dashboard Summary.
     */
    public function getDashboardSummary(): array
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        // Active employees
        $activeEmployees = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active')
            ->when($this->branchId, fn($q) => $q->where('branch_id', $this->branchId))
            ->count();

        // Today's attendance
        $todayAttendance = Attendance::where('organization_id', $this->organizationId)
            ->where('attendance_date', $today)
            ->when($this->branchId, fn($q) => $q->whereHas('employee', fn($e) => $e->where('branch_id', $this->branchId)))
            ->get();

        // Pending leave requests
        $pendingLeaves = LeaveRequest::where('organization_id', $this->organizationId)
            ->where('status', 'pending')
            ->when($this->branchId, fn($q) => $q->whereHas('employee', fn($e) => $e->where('branch_id', $this->branchId)))
            ->count();

        // Employees on leave today
        $onLeaveToday = LeaveRequest::where('organization_id', $this->organizationId)
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->when($this->branchId, fn($q) => $q->whereHas('employee', fn($e) => $e->where('branch_id', $this->branchId)))
            ->count();

        // New joiners this month
        $newJoiners = Employee::where('organization_id', $this->organizationId)
            ->whereBetween('date_of_joining', [$monthStart, $monthEnd])
            ->when($this->branchId, fn($q) => $q->where('branch_id', $this->branchId))
            ->count();

        // Expiring documents (next 30 days)
        $expiringDocs = DB::table('employee_documents as ed')
            ->join('employees as e', 'ed.employee_id', '=', 'e.id')
            ->where('e.organization_id', $this->organizationId)
            ->where('e.employment_status', 'active')
            ->whereBetween('ed.expiry_date', [$today, now()->addDays(30)->toDateString()])
            ->count();

        // Birthdays this month
        $birthdays = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active')
            ->whereMonth('date_of_birth', now()->month)
            ->when($this->branchId, fn($q) => $q->where('branch_id', $this->branchId))
            ->count();

        // Work anniversaries this month
        $anniversaries = Employee::where('organization_id', $this->organizationId)
            ->where('employment_status', 'active')
            ->whereMonth('date_of_joining', now()->month)
            ->whereYear('date_of_joining', '<', now()->year)
            ->when($this->branchId, fn($q) => $q->where('branch_id', $this->branchId))
            ->count();

        return [
            'active_employees' => $activeEmployees,
            'attendance_today' => [
                'present' => $todayAttendance->whereIn('status', ['present', 'late'])->count(),
                'absent' => $todayAttendance->where('status', 'absent')->count(),
                'late' => $todayAttendance->where('status', 'late')->count(),
                'on_leave' => $onLeaveToday,
            ],
            'pending_leave_requests' => $pendingLeaves,
            'new_joiners_this_month' => $newJoiners,
            'expiring_documents' => $expiringDocs,
            'birthdays_this_month' => $birthdays,
            'anniversaries_this_month' => $anniversaries,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}

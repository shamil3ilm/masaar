<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Models\HR\Attendance;
use App\Models\HR\Employee;
use App\Models\HR\EmployeeLoan;
use App\Models\HR\LeaveRequest;
use App\Models\HR\Payslip;
use App\Models\HR\PayslipItem;
use App\Models\HR\PayrollPeriod;
use App\Services\Accounting\JournalService;
use App\Services\Core\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function __construct(
        private JournalService $journalService,
        private NumberGeneratorService $numberGenerator,
        private AttendanceService $attendanceService
    ) {}

    /**
     * Create a payroll period.
     */
    public function createPeriod(array $data): PayrollPeriod
    {
        return PayrollPeriod::create([
            'organization_id' => auth()->user()->organization_id,
            'name' => $data['name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'payment_date' => $data['payment_date'] ?? null,
            'status' => PayrollPeriod::STATUS_OPEN,
        ]);
    }

    /**
     * Generate payslips for a payroll period.
     */
    public function generatePayslips(PayrollPeriod $period): int
    {
        if (!$period->canBeProcessed()) {
            throw new \InvalidArgumentException('Payroll period cannot be processed.');
        }

        return DB::transaction(function () use ($period) {
            $period->update(['status' => PayrollPeriod::STATUS_PROCESSING]);

            $employees = Employee::active()
                ->whereHas('currentSalary')
                ->get();

            $count = 0;

            foreach ($employees as $employee) {
                // Skip if payslip already exists
                if (Payslip::where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->exists()) {
                    continue;
                }

                $this->generatePayslip($period, $employee);
                $count++;
            }

            $period->update([
                'status' => PayrollPeriod::STATUS_PROCESSED,
                'processed_by' => auth()->id(),
                'processed_at' => now(),
            ]);

            return $count;
        });
    }

    /**
     * Generate payslip for a single employee.
     */
    public function generatePayslip(PayrollPeriod $period, Employee $employee): Payslip
    {
        $salary = $employee->currentSalary;

        if (!$salary) {
            throw new \InvalidArgumentException('Employee has no active salary assignment.');
        }

        // Get attendance summary
        $attendanceSummary = $this->attendanceService->getEmployeeSummary(
            $employee,
            $period->start_date,
            $period->end_date
        );

        // Get approved leaves (unpaid)
        $unpaidLeaveDays = LeaveRequest::forEmployee($employee->id)
            ->approved()
            ->inDateRange($period->start_date, $period->end_date)
            ->whereHas('leaveType', fn($q) => $q->where('is_paid', false))
            ->sum('total_days');

        // Create payslip
        $payslip = Payslip::create([
            'organization_id' => $employee->organization_id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'employee_salary_id' => $salary->id,
            'payslip_number' => $this->numberGenerator->generate('SLIP'),
            'payment_date' => $period->payment_date,
            'total_working_days' => $attendanceSummary['total_days'] - $attendanceSummary['holiday'] - $attendanceSummary['weekend'],
            'days_worked' => $attendanceSummary['working_days'],
            'days_on_leave' => $attendanceSummary['on_leave'],
            'unpaid_leave_days' => $unpaidLeaveDays,
            'overtime_hours' => $attendanceSummary['total_overtime_hours'],
            'currency_code' => $salary->currency_code,
            'status' => Payslip::STATUS_DRAFT,
        ]);

        // Calculate earnings and deductions
        $this->calculatePayslipItems($payslip, $salary, $attendanceSummary, $unpaidLeaveDays);

        return $payslip->fresh(['items', 'employee', 'payrollPeriod']);
    }

    /**
     * Calculate payslip items (earnings and deductions).
     */
    protected function calculatePayslipItems(
        Payslip $payslip,
        $salary,
        array $attendanceSummary,
        float $unpaidLeaveDays
    ): void {
        $sortOrder = 0;
        $grossEarnings = 0;
        $totalDeductions = 0;
        $taxableIncome = 0;

        // Pro-rata factor (for unpaid leave)
        $proRataFactor = 1;
        if ($payslip->total_working_days > 0 && $unpaidLeaveDays > 0) {
            $proRataFactor = ($payslip->total_working_days - $unpaidLeaveDays) / $payslip->total_working_days;
        }

        // Process earnings
        foreach ($salary->getEarnings() as $component) {
            $amount = $component->amount;

            // Apply pro-rata if component supports it
            if ($component->salaryComponent->is_pro_rata) {
                $amount = round($amount * $proRataFactor, 4);
            }

            if ($amount > 0) {
                PayslipItem::create([
                    'payslip_id' => $payslip->id,
                    'salary_component_id' => $component->salary_component_id,
                    'type' => 'earning',
                    'name' => $component->salaryComponent->name,
                    'amount' => $amount,
                    'ytd_amount' => $this->getYtdAmount($payslip->employee_id, $component->salary_component_id),
                    'sort_order' => $sortOrder++,
                ]);

                $grossEarnings = bcadd((string) $grossEarnings, (string) $amount, 4);

                if ($component->salaryComponent->is_taxable) {
                    $taxableIncome = bcadd((string) $taxableIncome, (string) $amount, 4);
                }
            }
        }

        // Process deductions
        foreach ($salary->getDeductions() as $component) {
            $amount = $component->amount;

            // Apply pro-rata if component supports it
            if ($component->salaryComponent->is_pro_rata) {
                $amount = round($amount * $proRataFactor, 4);
            }

            if ($amount > 0) {
                PayslipItem::create([
                    'payslip_id' => $payslip->id,
                    'salary_component_id' => $component->salary_component_id,
                    'type' => 'deduction',
                    'name' => $component->salaryComponent->name,
                    'amount' => $amount,
                    'ytd_amount' => $this->getYtdAmount($payslip->employee_id, $component->salary_component_id),
                    'sort_order' => $sortOrder++,
                ]);

                $totalDeductions = bcadd((string) $totalDeductions, (string) $amount, 4);
            }
        }

        // Add loan EMI deduction if applicable
        $loanDeduction = $this->calculateLoanDeduction($payslip);
        if ($loanDeduction > 0) {
            PayslipItem::create([
                'payslip_id' => $payslip->id,
                'salary_component_id' => null,
                'type' => 'deduction',
                'name' => 'Loan Repayment',
                'amount' => $loanDeduction,
                'ytd_amount' => 0,
                'sort_order' => $sortOrder++,
            ]);

            $totalDeductions = bcadd((string) $totalDeductions, (string) $loanDeduction, 4);
        }

        // Update payslip totals
        $payslip->update([
            'gross_earnings' => $grossEarnings,
            'total_deductions' => $totalDeductions,
            'net_salary' => max(0, bcsub((string) $grossEarnings, (string) $totalDeductions, 4)),
            'taxable_income' => $taxableIncome,
        ]);
    }

    /**
     * Calculate loan EMI deduction.
     */
    protected function calculateLoanDeduction(Payslip $payslip): float
    {
        $loans = EmployeeLoan::where('employee_id', $payslip->employee_id)
            ->active()
            ->get();

        $totalEmi = 0;

        foreach ($loans as $loan) {
            $nextRepayment = $loan->getNextRepayment();
            if ($nextRepayment && $nextRepayment->due_date->lte($payslip->payrollPeriod->end_date)) {
                $totalEmi = bcadd((string) $totalEmi, (string) $nextRepayment->total_amount, 4);
            }
        }

        return (float) $totalEmi;
    }

    /**
     * Get year-to-date amount for a component.
     */
    protected function getYtdAmount(int $employeeId, int $componentId): float
    {
        $currentYear = now()->year;

        return (float) PayslipItem::whereHas('payslip', function ($q) use ($employeeId, $currentYear) {
            $q->where('employee_id', $employeeId)
                ->whereYear('created_at', $currentYear)
                ->whereIn('status', [Payslip::STATUS_APPROVED, Payslip::STATUS_PAID]);
        })
            ->where('salary_component_id', $componentId)
            ->sum('amount');
    }

    /**
     * Approve a payslip.
     */
    public function approvePayslip(Payslip $payslip): Payslip
    {
        if ($payslip->status !== Payslip::STATUS_PENDING) {
            throw new \InvalidArgumentException('Only pending payslips can be approved.');
        }

        $payslip->update([
            'status' => Payslip::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return $payslip->fresh();
    }

    /**
     * Mark payslip as paid.
     */
    public function markAsPaid(Payslip $payslip, string $paymentMode, ?string $paymentReference = null): Payslip
    {
        if ($payslip->status !== Payslip::STATUS_APPROVED) {
            throw new \InvalidArgumentException('Only approved payslips can be marked as paid.');
        }

        return DB::transaction(function () use ($payslip, $paymentMode, $paymentReference) {
            // Create journal entry
            $journal = $this->createJournalEntry($payslip);

            // Process loan repayments
            $this->processLoanRepayments($payslip);

            $payslip->update([
                'status' => Payslip::STATUS_PAID,
                'payment_mode' => $paymentMode,
                'payment_reference' => $paymentReference,
                'paid_at' => now(),
                'journal_entry_id' => $journal->id,
            ]);

            return $payslip->fresh();
        });
    }

    /**
     * Create journal entry for payslip.
     */
    protected function createJournalEntry(Payslip $payslip): \App\Models\Accounting\JournalEntry
    {
        $salaryExpenseAccount = config('erp.default_accounts.salary_expense');
        $salaryPayableAccount = config('erp.default_accounts.salary_payable');

        $lines = [
            [
                'account_id' => $salaryExpenseAccount,
                'description' => "Salary - {$payslip->employee->getDisplayName()}",
                'debit' => $payslip->gross_earnings,
                'credit' => 0,
            ],
            [
                'account_id' => $salaryPayableAccount,
                'description' => "Net Salary Payable - {$payslip->employee->getDisplayName()}",
                'debit' => 0,
                'credit' => $payslip->net_salary,
            ],
        ];

        // Add statutory deduction accounts
        foreach ($payslip->deductions()->with('salaryComponent')->get() as $deduction) {
            if ($deduction->salaryComponent?->is_statutory) {
                $accountId = config("erp.statutory_accounts.{$deduction->salaryComponent->code}");
                if ($accountId) {
                    $lines[] = [
                        'account_id' => $accountId,
                        'description' => $deduction->name,
                        'debit' => 0,
                        'credit' => $deduction->amount,
                    ];
                }
            }
        }

        return $this->journalService->create([
            'entry_date' => $payslip->payment_date ?? now(),
            'reference' => $payslip->payslip_number,
            'description' => "Payroll - {$payslip->employee->getDisplayName()} - {$payslip->payrollPeriod->name}",
            'source_type' => Payslip::class,
            'source_id' => $payslip->id,
        ], $lines);
    }

    /**
     * Process loan repayments from payslip.
     */
    protected function processLoanRepayments(Payslip $payslip): void
    {
        $loans = EmployeeLoan::where('employee_id', $payslip->employee_id)
            ->active()
            ->get();

        foreach ($loans as $loan) {
            $nextRepayment = $loan->getNextRepayment();
            if ($nextRepayment && $nextRepayment->due_date->lte($payslip->payrollPeriod->end_date)) {
                $loan->recordRepayment($nextRepayment->total_amount, $payslip->id);
            }
        }
    }

    /**
     * Close a payroll period.
     */
    public function closePeriod(PayrollPeriod $period): PayrollPeriod
    {
        if (!$period->canBeClosed()) {
            throw new \InvalidArgumentException('Payroll period cannot be closed.');
        }

        // Check if all payslips are paid
        $unpaid = $period->payslips()
            ->whereNotIn('status', [Payslip::STATUS_PAID, Payslip::STATUS_CANCELLED])
            ->count();

        if ($unpaid > 0) {
            throw new \InvalidArgumentException("Cannot close period. {$unpaid} payslips are not yet paid.");
        }

        $period->update([
            'status' => PayrollPeriod::STATUS_CLOSED,
            'closed_by' => auth()->id(),
            'closed_at' => now(),
        ]);

        return $period->fresh();
    }

    /**
     * Get payroll summary for a period.
     */
    public function getPeriodSummary(PayrollPeriod $period): array
    {
        $payslips = $period->payslips()->get();

        return [
            'total_employees' => $payslips->count(),
            'total_gross' => $payslips->sum('gross_earnings'),
            'total_deductions' => $payslips->sum('total_deductions'),
            'total_net' => $payslips->sum('net_salary'),
            'draft_count' => $payslips->where('status', Payslip::STATUS_DRAFT)->count(),
            'pending_count' => $payslips->where('status', Payslip::STATUS_PENDING)->count(),
            'approved_count' => $payslips->where('status', Payslip::STATUS_APPROVED)->count(),
            'paid_count' => $payslips->where('status', Payslip::STATUS_PAID)->count(),
        ];
    }
}

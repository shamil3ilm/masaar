<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Loan;
use App\Services\Accounting\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function __construct(
        private LoanService $loanService
    ) {}

    /**
     * List loans.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Loan::with(['branch:id,name', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->when($request->has('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->has('loan_type'), fn($q) => $q->where('loan_type', $request->loan_type))
            ->when($request->has('employee_id'), fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->has('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($q) use ($search) {
                    $q->where('loan_number', 'like', "%{$search}%")
                        ->orWhere('borrower_name', 'like', "%{$search}%");
                });
            });

        $loans = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($loans);
    }

    /**
     * Create a new loan.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'loan_type' => ['required', 'string', 'in:employee_loan,inter_company,intra_company,bank_loan'],
            'loan_category' => ['nullable', 'string', 'in:personal,salary_advance,housing,vehicle,education'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'borrower_name' => ['nullable', 'string', 'max:255'],
            'lender_type' => ['nullable', 'string', 'in:organization,bank,other'],
            'lender_name' => ['nullable', 'string', 'max:255'],
            'lender_contact_id' => ['nullable', 'exists:contacts,id'],
            'principal_amount' => ['required', 'numeric', 'min:0.01'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'interest_type' => ['nullable', 'string', 'in:simple,compound,flat'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'disbursement_date' => ['required', 'date'],
            'first_payment_date' => ['required', 'date', 'after_or_equal:disbursement_date'],
            'maturity_date' => ['required', 'date', 'after:first_payment_date'],
            'tenure_months' => ['required', 'integer', 'min:1'],
            'payment_frequency' => ['nullable', 'string', 'in:weekly,bi-weekly,monthly'],
            'total_installments' => ['nullable', 'integer', 'min:1'],
            'loan_account_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'interest_account_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'deduct_from_payroll' => ['nullable', 'boolean'],
            'monthly_deduction' => ['nullable', 'numeric', 'min:0'],
            'purpose' => ['nullable', 'string'],
            'terms_conditions' => ['nullable', 'string'],
        ]);

        try {
            $loan = $this->loanService->create([
                ...$validated,
                'organization_id' => $this->organizationId($request),
            ], auth()->id());

            return $this->created($loan, 'Loan created successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    /**
     * Show a single loan with schedule and payments.
     */
    public function show(Loan $loan): JsonResponse
    {
        $loan->load([
            'branch:id,name',
            'schedules',
            'payments.receivedBy:id,name',
            'loanAccount:id,code,name',
            'interestAccount:id,code,name',
            'bankAccount:id,account_name,bank_name',
            'createdBy:id,name',
            'approvedBy:id,name',
        ]);

        return $this->success($loan);
    }

    /**
     * Update a loan (only pending loans).
     */
    public function update(Request $request, Loan $loan): JsonResponse
    {
        if ($loan->status !== Loan::STATUS_PENDING) {
            return $this->error('Only pending loans can be updated', 'INVALID_STATUS', 400);
        }

        $validated = $request->validate([
            'loan_category' => ['nullable', 'string'],
            'borrower_name' => ['nullable', 'string', 'max:255'],
            'interest_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'interest_type' => ['sometimes', 'string', 'in:simple,compound,flat'],
            'disbursement_date' => ['sometimes', 'date'],
            'first_payment_date' => ['sometimes', 'date'],
            'maturity_date' => ['sometimes', 'date'],
            'tenure_months' => ['sometimes', 'integer', 'min:1'],
            'purpose' => ['nullable', 'string'],
            'terms_conditions' => ['nullable', 'string'],
        ]);

        $loan->update($validated);

        // Regenerate schedule if key terms changed
        if (array_intersect_key($validated, array_flip(['interest_rate', 'interest_type', 'tenure_months', 'first_payment_date']))) {
            $this->loanService->generateSchedule($loan);
        }

        return $this->success($loan->fresh(['schedules']), 'Loan updated successfully');
    }

    /**
     * Approve or reject a loan.
     */
    public function review(Request $request, Loan $loan): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
        ]);

        if ($loan->approval_status !== Loan::APPROVAL_PENDING) {
            return $this->error('Loan is not pending approval', 'INVALID_STATUS', 400);
        }

        if ($validated['action'] === 'approve') {
            $loan->update([
                'approval_status' => Loan::APPROVAL_APPROVED,
                'status'          => Loan::STATUS_APPROVED,
                'approved_by'     => auth()->id(),
                'approved_at'     => now(),
            ]);
            return $this->success($loan->fresh(), 'Loan approved successfully');
        }

        $loan->update(['approval_status' => Loan::APPROVAL_REJECTED]);
        return $this->success($loan->fresh(), 'Loan rejected');
    }

    /**
     * Record a payment against a loan.
     */
    public function recordPayment(Request $request, Loan $loan): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['nullable', 'exists:loan_schedules,id'],
            'payment_date' => ['required', 'date'],
            'total_paid' => ['nullable', 'numeric', 'min:0.01'],
            'principal_paid' => ['nullable', 'numeric', 'min:0'],
            'interest_paid' => ['nullable', 'numeric', 'min:0'],
            'penalty_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,bank_transfer,payroll_deduction'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $payment = $this->loanService->recordPayment($loan, $validated, auth()->id());
            return $this->created($payment, 'Payment recorded successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'PAYMENT_FAILED', 400);
        }
    }

    /**
     * Get outstanding balance details.
     */
    public function outstandingBalance(Loan $loan): JsonResponse
    {
        $balance = $this->loanService->getOutstandingBalance($loan);
        return $this->success($balance);
    }

    /**
     * Regenerate loan schedule.
     */
    public function regenerateSchedule(Loan $loan): JsonResponse
    {
        if (!in_array($loan->status, [Loan::STATUS_PENDING, Loan::STATUS_APPROVED])) {
            return $this->error('Schedule can only be regenerated for pending or approved loans', 'INVALID_STATUS', 400);
        }

        $loan = $this->loanService->generateSchedule($loan);
        return $this->success($loan, 'Schedule regenerated successfully');
    }

    /**
     * Close a completed loan.
     */
    public function close(Loan $loan): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->loanService->close($loan),
            'Loan closed successfully',
            'CLOSE_FAILED',
            400
        );
    }
}

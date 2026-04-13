<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Expense;

use App\Http\Controllers\Controller;
use App\Models\Expense\Expense;
use App\Models\Expense\RecurringExpense;
use App\Services\Expense\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(
        private ExpenseService $expenseService
    ) {}

    /**
     * List expenses.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Expense::with(['category:id,name', 'createdBy:id,name'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        $query
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->start_date, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($request->end_date, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->when(
                $request->has('is_reimbursable'),
                fn ($q) => $q->where('is_reimbursable', $request->boolean('is_reimbursable'))
            )
            ->when($request->search, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('expense_number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%");
                });
            });

        $expenses = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($expenses);
    }

    /**
     * Create a new expense.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'category_id' => ['required', 'exists:expense_categories,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'supplier_id' => ['nullable', 'exists:contacts,id'],
            'expense_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'in:cash,card,bank_transfer,petty_cash'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0.01'],
            'is_reimbursable' => ['nullable', 'boolean'],
            'is_billable' => ['nullable', 'boolean'],
            'project_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'exists:contacts,id'],
            'account_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'notes' => ['nullable', 'string'],
            'custom_fields' => ['nullable', 'array'],
            'items' => ['nullable', 'array'],
            'items.*.category_id' => ['nullable', 'exists:expense_categories,id'],
            'items.*.description' => ['required', 'string'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_amount' => ['nullable', 'numeric', 'min:0.01'],
            'items.*.account_id' => ['nullable', 'exists:chart_of_accounts,id'],
        ]);

        try {
            $expense = $this->expenseService->create([
                ...$validated,
                'organization_id' => $this->organizationId($request),
            ], $request->user()->id);

            return $this->created($expense, 'Expense created successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    /**
     * Show a single expense.
     */
    public function show(Expense $expense): JsonResponse
    {
        $expense->load([
            'category',
            'items.category:id,name',
            'items.account:id,code,name',
            'receipts',
            'account:id,code,name',
            'bankAccount:id,account_name,bank_name',
            'createdBy:id,name',
            'approvedBy:id,name',
        ]);

        return $this->success($expense);
    }

    /**
     * Update an expense.
     */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['sometimes', 'exists:expense_categories,id'],
            'expense_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'in:cash,card,bank_transfer,petty_cash'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0.01'],
            'is_reimbursable' => ['nullable', 'boolean'],
            'is_billable' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.description' => ['required', 'string'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        return $this->tryAction(
            fn() => $this->expenseService->update($expense, $validated),
            'Expense updated successfully',
            'UPDATE_FAILED',
            400
        );
    }

    /**
     * Delete a draft expense.
     */
    public function destroy(Expense $expense): JsonResponse
    {
        if ($expense->status !== Expense::STATUS_DRAFT) {
            return $this->error('Only draft expenses can be deleted', 'INVALID_STATUS', 400);
        }

        $expense->delete();

        return $this->success(null, 'Expense deleted successfully');
    }

    /**
     * Submit an expense for approval.
     */
    public function submit(Expense $expense): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->expenseService->submit($expense),
            'Expense submitted for approval',
            'SUBMIT_FAILED',
            400
        );
    }

    /**
     * Approve or reject an expense.
     * POST /expenses/{id}/review  {"action": "approve"|"reject", "reason": "..."}
     */
    public function review(Request $request, Expense $expense): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->tryAction(
            function () use ($expense, $validated, $request) {
                return $validated['action'] === 'approve'
                    ? $this->expenseService->approve($expense, $request->user()->id)
                    : $this->expenseService->reject($expense, $validated['reason'] ?? null);
            },
            $validated['action'] === 'approve' ? 'Expense approved successfully' : 'Expense rejected',
            'REJECTION_FAILED',
            400
        );
    }

    /**
     * List recurring expenses.
     */
    public function recurringIndex(Request $request): JsonResponse
    {
        $query = RecurringExpense::with(['category:id,name', 'createdBy:id,name'])
            ->orderByDesc('created_at');

        $query->when(
            $request->has('is_active'),
            fn ($q) => $q->where('is_active', $request->boolean('is_active'))
        );

        $recurring = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($recurring);
    }

    /**
     * Create a recurring expense.
     */
    public function createRecurring(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:expense_categories,id'],
            'supplier_id' => ['nullable', 'exists:contacts,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly,quarterly,yearly'],
            'frequency_interval' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'next_occurrence' => ['nullable', 'date'],
            'max_occurrences' => ['nullable', 'integer', 'min:1'],
            'auto_approve' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $recurring = $this->expenseService->createRecurring([
            ...$validated,
            'organization_id' => $this->organizationId($request),
        ], $request->user()->id);

        return $this->created($recurring, 'Recurring expense created successfully');
    }

    /**
     * Process all due recurring expenses.
     */
    public function processRecurring(): JsonResponse
    {
        $results = $this->expenseService->processRecurring();
        return $this->success($results, 'Recurring expenses processed');
    }
}

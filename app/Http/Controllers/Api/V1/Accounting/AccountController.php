<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Services\Accounting\AccountBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(
        private AccountBalanceService $balanceService
    ) {}

    /**
     * List all accounts (tree structure).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Account::with('children')
            ->whereNull('parent_id')
            ->orderBy('code');

        if ($request->has('type')) {
            $query->where('account_type', $request->type);
        }

        if ($request->has('active_only') && $request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $accounts = $query->get();

        return $this->success($this->buildTree($accounts));
    }

    /**
     * List accounts as flat list (for dropdowns).
     */
    public function flat(Request $request): JsonResponse
    {
        $query = Account::query()
            ->orderBy('code');

        if ($request->has('type')) {
            $query->where('account_type', $request->type);
        }

        if ($request->has('postable') && $request->boolean('postable')) {
            $query->where('is_header', false)->where('is_active', true);
        }

        if ($request->has('active_only') && $request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $accounts = $query->get(['id', 'code', 'name', 'account_type', 'sub_type', 'is_header', 'is_active']);

        return $this->success($accounts);
    }

    /**
     * Show single account with balance.
     */
    public function show(Account $account, Request $request): JsonResponse
    {
        $account->load('parent', 'children');

        $data = $account->toArray();

        // Include balance if fiscal year provided
        if ($request->has('fiscal_year_id')) {
            $data['balance'] = $this->balanceService->getAccountBalance(
                $account->id,
                $request->integer('fiscal_year_id'),
                $request->input('as_of_date')
            );
        }

        return $this->success($data);
    }

    /**
     * Create new account.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('chart_of_accounts')
                    ->where('organization_id', auth()->user()->organization_id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'account_type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'sub_type' => ['required', 'string'],
            'currency_code' => ['nullable', 'exists:currencies,code'],
            'is_header' => ['boolean'],
        ]);

        // Calculate level and path
        $level = 1;
        $path = $validated['code'];

        if ($validated['parent_id']) {
            $parent = Account::findOrFail($validated['parent_id']);
            $level = $parent->level + 1;
            $path = "{$parent->path}.{$validated['code']}";
        }

        $account = Account::create([
            ...$validated,
            'organization_id' => auth()->user()->organization_id,
            'level' => $level,
            'path' => $path,
            'is_active' => true,
        ]);

        return $this->success($account, 'Account created successfully', 201);
    }

    /**
     * Update account.
     */
    public function update(Request $request, Account $account): JsonResponse
    {
        if ($account->is_system) {
            return $this->error('System accounts cannot be modified', 'SYSTEM_ACCOUNT', 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'exists:currencies,code'],
            'is_active' => ['boolean'],
        ]);

        $account->update($validated);

        return $this->success($account, 'Account updated successfully');
    }

    /**
     * Delete account (soft check for transactions).
     */
    public function destroy(Account $account): JsonResponse
    {
        if ($account->is_system) {
            return $this->error('System accounts cannot be deleted', 'SYSTEM_ACCOUNT', 403);
        }

        // Check for child accounts
        if ($account->children()->exists()) {
            return $this->error('Cannot delete account with child accounts', 'HAS_CHILDREN', 400);
        }

        // Check for journal entries
        if ($account->journalLines()->exists()) {
            return $this->error('Cannot delete account with journal entries', 'HAS_TRANSACTIONS', 400);
        }

        $account->delete();

        return $this->success(null, 'Account deleted successfully');
    }

    /**
     * Get account ledger.
     */
    public function ledger(Account $account, Request $request): JsonResponse
    {
        $ledger = $this->balanceService->getAccountLedger(
            $account->id,
            $request->input('fiscal_year_id'),
            $request->input('start_date'),
            $request->input('end_date'),
            $request->integer('limit', 100),
            $request->integer('offset', 0)
        );

        return $this->success($ledger);
    }

    /**
     * Build tree structure from accounts.
     */
    protected function buildTree($accounts): array
    {
        return $accounts->map(function ($account) {
            $data = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'sub_type' => $account->sub_type,
                'is_header' => $account->is_header,
                'is_system' => $account->is_system,
                'is_active' => $account->is_active,
            ];

            if ($account->children->isNotEmpty()) {
                $data['children'] = $this->buildTree($account->children);
            }

            return $data;
        })->toArray();
    }
}

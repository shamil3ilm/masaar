<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\CurrencyRevaluation;
use App\Models\Accounting\OrganizationCurrency;
use App\Services\Accounting\MultiCurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MultiCurrencyController extends Controller
{
    public function __construct(
        private MultiCurrencyService $multiCurrencyService
    ) {}

    /**
     * List organization currencies.
     */
    public function currencies(Request $request): JsonResponse
    {
        $activeOnly = $request->boolean('active_only', true);

        $currencies = $this->multiCurrencyService->getOrgCurrencies(
            $this->organizationId($request),
            $activeOnly
        );

        return $this->success($currencies);
    }

    /**
     * Add a currency to the organization.
     */
    public function addCurrency(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency_code' => ['required', 'string', 'max:3', 'exists:currencies,code'],
            'is_base_currency' => ['sometimes', 'boolean'],
            'exchange_gain_account_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'exchange_loss_account_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'rounding_account_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'rounding_precision' => ['sometimes', 'numeric', 'min:0.0001'],
            'rounding_method' => ['sometimes', 'in:round,ceil,floor'],
        ]);

        $validated['organization_id'] = $this->organizationId($request);

        // Check if currency already exists and is active
        $existing = OrganizationCurrency::withoutGlobalScopes()
            ->where('organization_id', $validated['organization_id'])
            ->where('currency_code', $validated['currency_code'])
            ->where('is_active', true)
            ->first();

        if ($existing) {
            return $this->error('Currency already added to organization', 'DUPLICATE', 422);
        }

        $orgCurrency = $this->multiCurrencyService->addCurrency($validated);

        return $this->created($orgCurrency);
    }

    /**
     * Remove (deactivate) a currency from the organization.
     */
    public function removeCurrency(Request $request, string $currencyCode): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->multiCurrencyService->removeCurrency($this->organizationId($request), $currencyCode),
            'Currency removed successfully',
            'VALIDATION_ERROR',
            400
        );
    }

    /**
     * List revaluations.
     */
    public function revaluations(Request $request): JsonResponse
    {
        $query = CurrencyRevaluation::with(['createdBy:id,name'])
            ->orderByDesc('revaluation_date')
            ->orderByDesc('id')
            ->when($request->has('status'), fn($q) => $q->forStatus($request->input('status')))
            ->when($request->has('currency_code'), fn($q) => $q->forCurrency($request->input('currency_code')))
            ->when($request->has('start_date') && $request->has('end_date'), fn($q) => $q->forDateRange($request->input('start_date'), $request->input('end_date')));

        $revaluations = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($revaluations);
    }

    /**
     * Create a revaluation.
     */
    public function createRevaluation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'revaluation_date' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'max:3'],
            'old_rate' => ['nullable', 'numeric', 'min:0.00000001'],
            'new_rate' => ['required', 'numeric', 'min:0.00000001'],
            'base_currency' => ['nullable', 'string', 'max:3'],
            'gain_loss_account_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'notes' => ['nullable', 'string'],
            'accounts' => ['nullable', 'array'],
            'accounts.*.account_id' => ['required_with:accounts', 'exists:chart_of_accounts,id'],
            'accounts.*.account_type' => ['required_with:accounts', 'in:receivable,payable,bank,asset,liability'],
            'accounts.*.foreign_currency_balance' => ['required_with:accounts', 'numeric'],
            'accounts.*.contact_id' => ['nullable', 'exists:contacts,id'],
        ]);

        $validated['organization_id'] = $this->organizationId($request);
        $validated['old_rate'] = $validated['old_rate'] ?? 0;
        $validated['base_currency'] = $validated['base_currency'] ?? 'SAR';

        try {
            $revaluation = $this->multiCurrencyService->revalue(
                collect($validated)->except('accounts')->toArray(),
                $validated['accounts'] ?? []
            );

            return $this->created($revaluation);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    /**
     * Show a revaluation.
     */
    public function showRevaluation(CurrencyRevaluation $currencyRevaluation): JsonResponse
    {
        $currencyRevaluation->load([
            'items.account:id,code,name',
            'items.contact:id,name',
            'gainLossAccount:id,code,name',
            'journalEntry',
            'createdBy:id,name',
        ]);

        return $this->success($currencyRevaluation);
    }

    /**
     * Post a revaluation.
     */
    public function postRevaluation(Request $request, CurrencyRevaluation $currencyRevaluation): JsonResponse
    {
        $validated = $request->validate([
            'gain_loss_account_id' => ['nullable', 'exists:chart_of_accounts,id'],
        ]);

        return $this->tryAction(
            fn() => $this->multiCurrencyService->postRevaluation($currencyRevaluation, $validated['gain_loss_account_id'] ?? null),
            'Revaluation posted successfully',
            'POST_FAILED',
            400
        );
    }

    /**
     * Auto-run period-end FX revaluation (SAP F.05 equivalent).
     * Scans all foreign-currency GL accounts and creates revaluation entries automatically.
     */
    public function autoRunRevaluation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'revaluation_date' => ['required', 'date'],
            'base_currency'    => ['nullable', 'string', 'max:3'],
            'auto_post'        => ['sometimes', 'boolean'],
        ]);

        try {
            $revaluation = $this->multiCurrencyService->autoRun(
                organizationId: $this->organizationId($request),
                revaluationDate: $validated['revaluation_date'],
                baseCurrency: $validated['base_currency'] ?? 'SAR',
                autoPost: $validated['auto_post'] ?? false,
                createdBy: $request->user()?->id,
            );

            return $this->created($revaluation->load(['items.account:id,code,name']));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'AUTO_RUN_FAILED', 422);
        }
    }

    /**
     * Reverse a posted revaluation.
     */
    public function reverseRevaluation(CurrencyRevaluation $currencyRevaluation): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->multiCurrencyService->reverseRevaluation($currencyRevaluation),
            'Revaluation reversed successfully',
            'REVERSE_FAILED',
            400
        );
    }

    /**
     * Get forex gain/loss report.
     */
    public function forexReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'max:3'],
            'entry_type' => ['nullable', 'in:realized,unrealized'],
        ]);

        $report = $this->multiCurrencyService->getExchangeGainLossReport(
            $this->organizationId($request),
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $validated['currency'] ?? null,
            $validated['entry_type'] ?? null
        );

        return $this->success($report);
    }
}

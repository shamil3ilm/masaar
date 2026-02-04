<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private AccountBalanceService $balanceService
    ) {}

    /**
     * Get trial balance.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $trialBalance = $this->balanceService->getTrialBalance(
            auth()->user()->organization_id,
            $validated['fiscal_year_id'],
            $validated['as_of_date'] ?? null
        );

        return $this->success($trialBalance);
    }

    /**
     * Get balance sheet summary.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $balanceSheet = $this->balanceService->getBalanceSheetSummary(
            auth()->user()->organization_id,
            $validated['fiscal_year_id'],
            $validated['as_of_date'] ?? null
        );

        return $this->success($balanceSheet);
    }

    /**
     * Get income statement (P&L) summary.
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $incomeStatement = $this->balanceService->getIncomeStatementSummary(
            auth()->user()->organization_id,
            $validated['fiscal_year_id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null
        );

        return $this->success($incomeStatement);
    }
}

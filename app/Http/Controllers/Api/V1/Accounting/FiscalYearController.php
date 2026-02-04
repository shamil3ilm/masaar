<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\FiscalYear;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalYearController extends Controller
{
    /**
     * List fiscal years.
     */
    public function index(): JsonResponse
    {
        $fiscalYears = FiscalYear::orderByDesc('start_date')->get();

        return $this->success($fiscalYears);
    }

    /**
     * Get current fiscal year.
     */
    public function current(): JsonResponse
    {
        $fiscalYear = FiscalYear::current(auth()->user()->organization_id);

        if (!$fiscalYear) {
            return $this->error('No current fiscal year set', 'NOT_FOUND', 404);
        }

        return $this->success($fiscalYear);
    }

    /**
     * Show single fiscal year.
     */
    public function show(FiscalYear $fiscalYear): JsonResponse
    {
        $fiscalYear->load(['periods', 'closedByUser:id,name']);

        return $this->success($fiscalYear);
    }

    /**
     * Create new fiscal year.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_current' => ['boolean'],
            'create_periods' => ['boolean'], // Create monthly periods
        ]);

        // Check for overlapping fiscal years
        $overlap = FiscalYear::where(function ($q) use ($validated) {
            $q->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']])
                ->orWhere(function ($q2) use ($validated) {
                    $q2->where('start_date', '<=', $validated['start_date'])
                        ->where('end_date', '>=', $validated['end_date']);
                });
        })->exists();

        if ($overlap) {
            return $this->error('Fiscal year dates overlap with an existing fiscal year', 'OVERLAP', 400);
        }

        $fiscalYear = FiscalYear::create([
            'organization_id' => auth()->user()->organization_id,
            'name' => $validated['name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
        ]);

        if ($validated['is_current'] ?? false) {
            $fiscalYear->setAsCurrent();
        }

        // Create monthly periods if requested
        if ($validated['create_periods'] ?? false) {
            $this->createMonthlyPeriods($fiscalYear);
        }

        return $this->success($fiscalYear->fresh(['periods']), 'Fiscal year created successfully', 201);
    }

    /**
     * Update fiscal year.
     */
    public function update(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        if ($fiscalYear->is_closed) {
            return $this->error('Closed fiscal years cannot be modified', 'CLOSED', 400);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:50'],
        ]);

        $fiscalYear->update($validated);

        return $this->success($fiscalYear, 'Fiscal year updated successfully');
    }

    /**
     * Set fiscal year as current.
     */
    public function setCurrent(FiscalYear $fiscalYear): JsonResponse
    {
        if ($fiscalYear->is_closed) {
            return $this->error('Closed fiscal years cannot be set as current', 'CLOSED', 400);
        }

        $fiscalYear->setAsCurrent();

        return $this->success($fiscalYear, 'Fiscal year set as current');
    }

    /**
     * Close fiscal year.
     */
    public function close(FiscalYear $fiscalYear): JsonResponse
    {
        if ($fiscalYear->is_closed) {
            return $this->error('Fiscal year is already closed', 'ALREADY_CLOSED', 400);
        }

        // Check for unclosed periods
        $openPeriods = $fiscalYear->periods()->where('is_closed', false)->count();
        if ($openPeriods > 0) {
            return $this->error('All accounting periods must be closed first', 'OPEN_PERIODS', 400);
        }

        // Check for draft journal entries
        $draftEntries = $fiscalYear->journalEntries()->where('status', 'draft')->count();
        if ($draftEntries > 0) {
            return $this->error('There are still draft journal entries in this fiscal year', 'DRAFT_ENTRIES', 400);
        }

        $fiscalYear->close();

        return $this->success($fiscalYear->fresh(), 'Fiscal year closed successfully');
    }

    /**
     * Delete fiscal year (only if no transactions).
     */
    public function destroy(FiscalYear $fiscalYear): JsonResponse
    {
        if ($fiscalYear->journalEntries()->exists()) {
            return $this->error('Cannot delete fiscal year with journal entries', 'HAS_ENTRIES', 400);
        }

        $fiscalYear->periods()->delete();
        $fiscalYear->delete();

        return $this->success(null, 'Fiscal year deleted successfully');
    }

    /**
     * Initialize organization with chart of accounts.
     */
    public function initializeChartOfAccounts(): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        // Check if COA already exists
        $existing = \App\Models\Accounting\Account::where('organization_id', $organizationId)->count();
        if ($existing > 0) {
            return $this->error('Chart of accounts already exists', 'ALREADY_EXISTS', 400);
        }

        $seeder = new ChartOfAccountsSeeder();
        $seeder->createDefaultAccounts($organizationId);

        return $this->success(null, 'Chart of accounts initialized successfully');
    }

    /**
     * Create monthly periods for a fiscal year.
     */
    protected function createMonthlyPeriods(FiscalYear $fiscalYear): void
    {
        $start = $fiscalYear->start_date->copy();
        $end = $fiscalYear->end_date;
        $periodNumber = 1;

        while ($start->lte($end)) {
            $periodEnd = $start->copy()->endOfMonth();
            if ($periodEnd->gt($end)) {
                $periodEnd = $end;
            }

            $fiscalYear->periods()->create([
                'period_number' => $periodNumber,
                'period_type' => 'month',
                'start_date' => $start->toDateString(),
                'end_date' => $periodEnd->toDateString(),
            ]);

            $start->addMonth()->startOfMonth();
            $periodNumber++;
        }
    }
}

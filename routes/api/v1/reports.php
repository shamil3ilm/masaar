<?php

use App\Http\Controllers\Api\V1\Reports\DashboardController;
use App\Http\Controllers\Api\V1\Reports\ExportController;
use App\Http\Controllers\Api\V1\Reports\FinancialReportController;
use App\Http\Controllers\Api\V1\Reports\ReportsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reports & Dashboard API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/sales', [DashboardController::class, 'sales']);
        Route::get('/purchase', [DashboardController::class, 'purchase']);
        Route::get('/inventory', [DashboardController::class, 'inventory']);
        Route::get('/hr', [DashboardController::class, 'hr']);
        Route::get('/crm', [DashboardController::class, 'crm']);
        Route::get('/manufacturing', [DashboardController::class, 'manufacturing']);
        Route::get('/recent-activity', [DashboardController::class, 'recentActivity']);
        Route::get('/alerts', [DashboardController::class, 'alerts']);
    });

    /*
    |--------------------------------------------------------------------------
    | Reports - Main Controller
    |--------------------------------------------------------------------------
    */
    Route::prefix('reports')->group(function () {

        // Report types and metadata
        Route::get('/types', [ReportsController::class, 'types'])
            ->name('api.v1.reports.types');

        // ==========================================
        // Financial Reports
        // ==========================================
        Route::prefix('financial')->group(function () {
            // Legacy routes (keep for backwards compatibility)
            Route::get('/profit-loss', [FinancialReportController::class, 'profitAndLoss']);
            Route::get('/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
            Route::get('/cash-flow', [FinancialReportController::class, 'cashFlow']);
            Route::get('/trial-balance', [FinancialReportController::class, 'trialBalance']);
            Route::get('/receivable-aging', [FinancialReportController::class, 'receivableAging']);
            Route::get('/payable-aging', [FinancialReportController::class, 'payableAging']);

            // New comprehensive routes
            Route::get('/balance-sheet-v2', [ReportsController::class, 'balanceSheet'])
                ->middleware('check.permission:accounting.reports.view')
                ->name('api.v1.reports.balance-sheet');

            Route::get('/income-statement', [ReportsController::class, 'incomeStatement'])
                ->middleware('check.permission:accounting.reports.view')
                ->name('api.v1.reports.income-statement');

            Route::get('/trial-balance-v2', [ReportsController::class, 'trialBalance'])
                ->middleware('check.permission:accounting.reports.view')
                ->name('api.v1.reports.trial-balance');

            Route::get('/cash-flow-v2', [ReportsController::class, 'cashFlow'])
                ->middleware('check.permission:accounting.reports.view')
                ->name('api.v1.reports.cash-flow');

            Route::get('/aged-receivables', [ReportsController::class, 'agedReceivables'])
                ->middleware('check.permission:accounting.reports.view')
                ->name('api.v1.reports.aged-receivables');

            Route::get('/aged-payables', [ReportsController::class, 'agedPayables'])
                ->middleware('check.permission:accounting.reports.view')
                ->name('api.v1.reports.aged-payables');
        });

        // ==========================================
        // Inventory Reports
        // ==========================================
        Route::prefix('inventory')->group(function () {
            Route::get('/stock-valuation', [ReportsController::class, 'stockValuation'])
                ->middleware('check.permission:inventory.reports.view')
                ->name('api.v1.reports.stock-valuation');

            Route::get('/stock-movement', [ReportsController::class, 'stockMovement'])
                ->middleware('check.permission:inventory.reports.view')
                ->name('api.v1.reports.stock-movement');

            Route::get('/low-stock', [ReportsController::class, 'lowStock'])
                ->middleware('check.permission:inventory.reports.view')
                ->name('api.v1.reports.low-stock');

            Route::get('/inventory-turnover', [ReportsController::class, 'inventoryTurnover'])
                ->middleware('check.permission:inventory.reports.view')
                ->name('api.v1.reports.inventory-turnover');

            Route::get('/batch-expiry', [ReportsController::class, 'batchExpiry'])
                ->middleware('check.permission:inventory.reports.view')
                ->name('api.v1.reports.batch-expiry');
        });

        // ==========================================
        // Sales Reports
        // ==========================================
        Route::prefix('sales')->group(function () {
            Route::get('/by-customer', [ReportsController::class, 'salesByCustomer'])
                ->middleware('check.permission:sales.reports.view')
                ->name('api.v1.reports.sales-by-customer');

            Route::get('/by-product', [ReportsController::class, 'salesByProduct'])
                ->middleware('check.permission:sales.reports.view')
                ->name('api.v1.reports.sales-by-product');

            Route::get('/by-salesperson', [ReportsController::class, 'salesBySalesperson'])
                ->middleware('check.permission:sales.reports.view')
                ->name('api.v1.reports.sales-by-salesperson');

            Route::get('/trend', [ReportsController::class, 'salesTrend'])
                ->middleware('check.permission:sales.reports.view')
                ->name('api.v1.reports.sales-trend');

            Route::get('/summary', [ReportsController::class, 'salesSummary'])
                ->middleware('check.permission:sales.reports.view')
                ->name('api.v1.reports.sales-summary');
        });

        // ==========================================
        // Export & Download
        // ==========================================
        Route::post('/export', [ReportsController::class, 'export'])
            ->name('api.v1.reports.export');

        Route::get('/download/{executionId}', [ReportsController::class, 'download'])
            ->name('api.v1.reports.download');

        Route::get('/history', [ReportsController::class, 'executionHistory'])
            ->name('api.v1.reports.history');

        // ==========================================
        // Saved Reports
        // ==========================================
        Route::prefix('saved')->group(function () {
            Route::get('/', [ReportsController::class, 'savedReports'])
                ->name('api.v1.reports.saved.index');

            Route::post('/', [ReportsController::class, 'createSavedReport'])
                ->name('api.v1.reports.saved.store');

            Route::put('/{id}', [ReportsController::class, 'updateSavedReport'])
                ->name('api.v1.reports.saved.update');

            Route::delete('/{id}', [ReportsController::class, 'deleteSavedReport'])
                ->name('api.v1.reports.saved.destroy');

            Route::post('/{id}/run', [ReportsController::class, 'runSavedReport'])
                ->name('api.v1.reports.saved.run');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Export Routes (Legacy)
    |--------------------------------------------------------------------------
    */
    Route::prefix('export')->group(function () {
        Route::get('/invoices', [ExportController::class, 'exportInvoices']);
        Route::get('/invoices/{invoice}/pdf', [ExportController::class, 'exportInvoicePdf']);
        Route::get('/trial-balance', [ExportController::class, 'exportTrialBalance']);
        Route::get('/profit-loss', [ExportController::class, 'exportProfitLoss']);
        Route::get('/receivable-aging', [ExportController::class, 'exportReceivableAging']);
        Route::get('/payable-aging', [ExportController::class, 'exportPayableAging']);
    });
});

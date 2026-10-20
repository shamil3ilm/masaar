<?php

use App\Domains\Billing\Http\Controllers\AdminFinanceReportsController;
use App\Domains\Billing\Http\Controllers\AdminFinanceSettingsController;
use App\Domains\Billing\Http\Controllers\AdminFreezeController;
use App\Domains\Billing\Http\Controllers\AdminPaymentsController;
use App\Domains\Billing\Http\Controllers\AdminReceivablesController;
use App\Domains\Billing\Http\Controllers\AdminReconciliationController;
use App\Domains\Billing\Services\FinanceReports;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin › Finance
|--------------------------------------------------------------------------
|
| How the platform bills its own customers. Loaded inside routes/web.php's
| admin group, so each route has the console's middleware - an admin with
| console.view, every change on the audit record - and is named admin.*.
| Every admin reads these pages; a change takes billing.manage.
|
*/

Route::prefix('finance')->name('finance.')->group(function () {
    Route::get('/settings', [AdminFinanceSettingsController::class, 'index'])->name('settings');
    Route::middleware('admin.can:billing.manage')->name('settings.')->prefix('settings')->group(function () {
        Route::post('/currencies', [AdminFinanceSettingsController::class, 'currencies'])->name('currencies');
        Route::post('/methods', [AdminFinanceSettingsController::class, 'methods'])->name('methods');
        Route::post('/terms', [AdminFinanceSettingsController::class, 'terms'])->name('terms');
        Route::post('/exchange', [AdminFinanceSettingsController::class, 'exchange'])->name('exchange');
        Route::post('/rates', [AdminFinanceSettingsController::class, 'rate'])->name('rates.store');
        Route::post('/reconciliation', [AdminFinanceSettingsController::class, 'reconciliation'])->name('reconciliation');
        Route::post('/freeze', [AdminFreezeController::class, 'policy'])->name('freeze');
        Route::post('/bank-accounts', [AdminFinanceSettingsController::class, 'addAccount'])->name('bank-accounts.store');
        Route::post('/bank-accounts/{key}/remove', [AdminFinanceSettingsController::class, 'removeAccount'])->whereUuid('key')->name('bank-accounts.remove');
        Route::post('/statement-profiles', [AdminFinanceSettingsController::class, 'addProfile'])->name('profiles.store');
        Route::post('/statement-profiles/{key}/remove', [AdminFinanceSettingsController::class, 'removeProfile'])->whereUuid('key')->name('profiles.remove');
    });

    // Reconciliation: bank statements and the card gateway's settlement
    // reports brought in, and their lines matched to what customers owe.
    Route::prefix('reconciliation')->name('reconciliation.')->group(function () {
        Route::get('/', [AdminReconciliationController::class, 'imports'])->name('imports');
        Route::post('/imports', [AdminReconciliationController::class, 'upload'])->middleware(['admin.can:billing.manage', 'throttle:20,1'])->name('imports.store');
    });

    // Every customer's invoices, and what is owed by age.
    Route::get('/receivables', [AdminReceivablesController::class, 'index'])->name('receivables');
    Route::get('/receivables/export.csv', [AdminReceivablesController::class, 'export'])->middleware('throttle:20,1')->name('receivables.export');

    // Receivables, payments by method and currency, VAT on the platform's
    // own invoices, collections and ageing - and each as a CSV.
    Route::get('/reports', [AdminFinanceReportsController::class, 'index'])->name('reports');
    Route::get('/reports/{report}.csv', [AdminFinanceReportsController::class, 'csv'])->whereIn('report', FinanceReports::REPORTS)->middleware('throttle:20,1')->name('reports.csv');
});

// One customer's account, beside its plan and payments (admin.billing.show).
Route::prefix('organizations/{id}/billing')->name('billing.')->group(function () {
    Route::post('/invoices', [AdminReceivablesController::class, 'issue'])->middleware('admin.can:billing.manage')->name('receivables.issue');
    Route::get('/invoices/{receivableId}', [AdminReceivablesController::class, 'show'])->whereUuid('receivableId')->name('receivables.show');
    Route::get('/invoices/{receivableId}/pdf', [AdminReceivablesController::class, 'pdf'])->whereUuid('receivableId')->name('receivables.pdf');
    // Money from and back to the customer.
    Route::middleware('admin.can:billing.manage')->group(function () {
        Route::post('/transfers', [AdminPaymentsController::class, 'transfer'])->name('transfers.store');
        Route::post('/invoices/{receivableId}/refund', [AdminPaymentsController::class, 'refund'])->whereUuid('receivableId')->name('receivables.refund');
        Route::post('/invoices/{receivableId}/write-off', [AdminPaymentsController::class, 'writeOff'])->whereUuid('receivableId')->name('receivables.write-off');
        Route::post('/credits/apply', [AdminPaymentsController::class, 'applyCredit'])->name('credits.apply');
        Route::post('/credits/{creditId}/pay-out', [AdminPaymentsController::class, 'payOutCredit'])->whereUuid('creditId')->name('credits.pay-out');
        // The overdue-payment freeze: this customer's stages, and by hand.
        Route::post('/freeze-policy', [AdminFreezeController::class, 'customerPolicy'])->name('freeze-policy');
        Route::post('/freeze', [AdminFreezeController::class, 'freeze'])->name('freeze');
        Route::post('/unfreeze', [AdminFreezeController::class, 'unfreeze'])->name('unfreeze');
    });
    Route::post('/terms', [AdminReceivablesController::class, 'terms'])->middleware('admin.can:billing.manage')->name('terms');
    Route::get('/statement', [AdminReceivablesController::class, 'statement'])->name('statement');
    Route::get('/statement.csv', [AdminReceivablesController::class, 'statementCsv'])->middleware('throttle:20,1')->name('statement.csv');
});

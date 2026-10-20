<?php

use App\Domains\Billing\Http\Controllers\AdminFinanceSettingsController;
use App\Domains\Billing\Http\Controllers\AdminReceivablesController;
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
        Route::post('/bank-accounts', [AdminFinanceSettingsController::class, 'addAccount'])->name('bank-accounts.store');
        Route::post('/bank-accounts/{key}/remove', [AdminFinanceSettingsController::class, 'removeAccount'])->whereUuid('key')->name('bank-accounts.remove');
    });

    // Every customer's invoices, and what is owed by age.
    Route::get('/receivables', [AdminReceivablesController::class, 'index'])->name('receivables');
    Route::get('/receivables/export.csv', [AdminReceivablesController::class, 'export'])->middleware('throttle:20,1')->name('receivables.export');
});

// One customer's account, beside its plan and payments (admin.billing.show).
Route::prefix('organizations/{id}/billing')->name('billing.')->group(function () {
    Route::post('/invoices', [AdminReceivablesController::class, 'issue'])->middleware('admin.can:billing.manage')->name('receivables.issue');
    Route::get('/invoices/{receivableId}/pdf', [AdminReceivablesController::class, 'pdf'])->whereUuid('receivableId')->name('receivables.pdf');
    Route::post('/terms', [AdminReceivablesController::class, 'terms'])->middleware('admin.can:billing.manage')->name('terms');
    Route::get('/statement', [AdminReceivablesController::class, 'statement'])->name('statement');
    Route::get('/statement.csv', [AdminReceivablesController::class, 'statementCsv'])->middleware('throttle:20,1')->name('statement.csv');
});

<?php

use App\Domains\Billing\Http\Controllers\AdminExpenseReportsController;
use App\Domains\Billing\Http\Controllers\AdminExpensesController;
use App\Domains\Billing\Http\Controllers\AdminFinanceReportsController;
use App\Domains\Billing\Http\Controllers\AdminFinanceSettingsController;
use App\Domains\Billing\Http\Controllers\AdminFreezeController;
use App\Domains\Billing\Http\Controllers\AdminPayablesController;
use App\Domains\Billing\Http\Controllers\AdminPaymentsController;
use App\Domains\Billing\Http\Controllers\AdminReceivablesController;
use App\Domains\Billing\Http\Controllers\AdminReconciliationController;
use App\Domains\Billing\Http\Controllers\AdminRecurringExpensesController;
use App\Domains\Billing\Http\Controllers\AdminSuppliersController;
use App\Domains\Billing\Services\ExpenseReports;
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
        Route::post('/purchase-vat', [AdminFinanceSettingsController::class, 'purchaseVat'])->name('purchase-vat');
        Route::post('/expenses', [AdminFinanceSettingsController::class, 'expenses'])->name('expenses');
        // The expense categories and cost centres.
        Route::post('/lists/{list}', [AdminFinanceSettingsController::class, 'addNamed'])->whereIn('list', array_keys(AdminFinanceSettingsController::LISTS))->name('lists.store');
        Route::post('/lists/{list}/{key}/remove', [AdminFinanceSettingsController::class, 'removeNamed'])->whereIn('list', array_keys(AdminFinanceSettingsController::LISTS))->where('key', '[a-z0-9_]{1,40}')->name('lists.remove');
    });

    // Reconciliation: bank statements and the card gateway's settlement
    // reports brought in, and their lines matched to what customers owe.
    Route::prefix('reconciliation')->name('reconciliation.')->whereUuid(['lineId', 'matchId'])->group(function () {
        Route::get('/', [AdminReconciliationController::class, 'imports'])->name('imports');
        Route::post('/imports', [AdminReconciliationController::class, 'upload'])->middleware(['admin.can:billing.manage', 'throttle:20,1'])->name('imports.store');
        Route::get('/lines', [AdminReconciliationController::class, 'lines'])->name('lines');
        Route::get('/lines/export.csv', [AdminReconciliationController::class, 'export'])->middleware('throttle:20,1')->name('lines.export');
        Route::get('/lines/{lineId}', [AdminReconciliationController::class, 'line'])->name('line');
        Route::get('/exceptions', [AdminReconciliationController::class, 'exceptions'])->name('exceptions');
        Route::get('/reports', [AdminReconciliationController::class, 'reports'])->name('reports');

        Route::middleware('admin.can:billing.manage')->group(function () {
            Route::post('/lines/{lineId}/suggest', [AdminReconciliationController::class, 'suggest'])->name('line.suggest');
            Route::post('/lines/{lineId}/match', [AdminReconciliationController::class, 'match'])->name('line.match');
            Route::post('/lines/{lineId}/ignore', [AdminReconciliationController::class, 'ignore'])->name('line.ignore');
            Route::post('/lines/{lineId}/restore', [AdminReconciliationController::class, 'restore'])->name('line.restore');
            Route::post('/matches/{matchId}/confirm', [AdminReconciliationController::class, 'confirm'])->name('match.confirm');
            Route::post('/matches/{matchId}/reject', [AdminReconciliationController::class, 'reject'])->name('match.reject');
            Route::post('/matches/{matchId}/split', [AdminReconciliationController::class, 'split'])->name('match.split');
            Route::post('/matches/{matchId}/unmatch', [AdminReconciliationController::class, 'unmatch'])->name('match.unmatch');
        });
    });

    // Every customer's invoices, and what is owed by age.
    Route::get('/receivables', [AdminReceivablesController::class, 'index'])->name('receivables');
    Route::get('/receivables/export.csv', [AdminReceivablesController::class, 'export'])->middleware('throttle:20,1')->name('receivables.export');

    // Who the platform pays. A supplier an expense names is archived, not
    // removed.
    Route::get('/suppliers', [AdminSuppliersController::class, 'index'])->name('suppliers');
    Route::get('/suppliers/export.csv', [AdminSuppliersController::class, 'export'])->middleware('throttle:20,1')->name('suppliers.export');
    Route::get('/suppliers/create', [AdminSuppliersController::class, 'create'])->name('suppliers.create');
    Route::post('/suppliers', [AdminSuppliersController::class, 'store'])->middleware('admin.can:billing.manage')->name('suppliers.store');
    Route::prefix('/suppliers/{supplierId}')->whereUuid('supplierId')->name('suppliers.')->group(function () {
        Route::get('/', [AdminSuppliersController::class, 'show'])->name('show');
        Route::middleware('admin.can:billing.manage')->group(function () {
            Route::post('/', [AdminSuppliersController::class, 'update'])->name('update');
            Route::post('/archive', [AdminSuppliersController::class, 'archive'])->name('archive');
            Route::post('/unarchive', [AdminSuppliersController::class, 'unarchive'])->name('unarchive');
            Route::post('/remove', [AdminSuppliersController::class, 'remove'])->name('remove');
            Route::post('/restore', [AdminSuppliersController::class, 'restore'])->name('restore');
        });
    });

    // What the platform spent, with its receipts. A mistaken expense is
    // void, never deleted.
    Route::get('/expenses', [AdminExpensesController::class, 'index'])->name('expenses');
    Route::get('/expenses/export.csv', [AdminExpensesController::class, 'export'])->middleware('throttle:20,1')->name('expenses.export');
    Route::get('/expenses/create', [AdminExpensesController::class, 'create'])->name('expenses.create');
    Route::post('/expenses', [AdminExpensesController::class, 'store'])->middleware(['admin.can:billing.manage', 'throttle:30,1'])->name('expenses.store');
    Route::prefix('/expenses/{expenseId}')->whereUuid('expenseId')->name('expenses.')->group(function () {
        Route::get('/', [AdminExpensesController::class, 'show'])->name('show');
        Route::get('/receipt', [AdminExpensesController::class, 'receipt'])->name('receipt');
        Route::get('/receipt/preview', [AdminExpensesController::class, 'preview'])->name('receipt.preview');
        Route::middleware('admin.can:billing.manage')->group(function () {
            Route::get('/edit', [AdminExpensesController::class, 'edit'])->name('edit');
            Route::post('/', [AdminExpensesController::class, 'update'])->middleware('throttle:30,1')->name('update');
            Route::post('/record', [AdminExpensesController::class, 'confirm'])->name('confirm');
            Route::post('/void', [AdminExpensesController::class, 'void'])->name('void');
            // Paying the supplier: a large payment and every write-off go
            // through their approval policies.
            Route::post('/payments', [AdminPayablesController::class, 'pay'])->middleware('throttle:30,1')->name('pay');
            Route::post('/write-off', [AdminPayablesController::class, 'writeOff'])->name('write-off');
            // The supplier's credit notes, and a payment taken back.
            Route::post('/credit-notes', [AdminPayablesController::class, 'credit'])->middleware('throttle:30,1')->name('credit-notes.store');
            Route::post('/payments/{paymentId}/reverse', [AdminPayablesController::class, 'reverse'])->whereUuid('paymentId')->name('payments.reverse');
        });
    });

    // Expenses by category, supplier, month and cost centre; the VAT on
    // them for the return; payables by age; and profit and loss by month -
    // and each as a CSV.
    Route::get('/expense-reports', [AdminExpenseReportsController::class, 'index'])->name('expense-reports');
    Route::get('/expense-reports/{report}.csv', [AdminExpenseReportsController::class, 'csv'])->whereIn('report', ExpenseReports::REPORTS)->middleware('throttle:20,1')->name('expense-reports.csv');

    // What the platform owes its suppliers, by age.
    Route::get('/payables', [AdminPayablesController::class, 'index'])->name('payables');
    Route::get('/payables/export.csv', [AdminPayablesController::class, 'export'])->middleware('throttle:20,1')->name('payables.export');

    // What the platform expects to pay every month or year: each bill is
    // drafted when due (billing:expect-expenses), and one not entered in
    // time is shown as missing.
    Route::get('/recurring', [AdminRecurringExpensesController::class, 'index'])->name('recurring');
    Route::get('/recurring/export.csv', [AdminRecurringExpensesController::class, 'export'])->middleware('throttle:20,1')->name('recurring.export');
    Route::get('/recurring/create', [AdminRecurringExpensesController::class, 'create'])->name('recurring.create');
    Route::post('/recurring', [AdminRecurringExpensesController::class, 'store'])->middleware('admin.can:billing.manage')->name('recurring.store');
    Route::prefix('/recurring/{recurringId}')->whereUuid('recurringId')->name('recurring.')->group(function () {
        Route::get('/', [AdminRecurringExpensesController::class, 'show'])->name('show');
        Route::middleware('admin.can:billing.manage')->group(function () {
            Route::post('/', [AdminRecurringExpensesController::class, 'update'])->name('update');
            Route::post('/remove', [AdminRecurringExpensesController::class, 'remove'])->name('remove');
            Route::post('/restore', [AdminRecurringExpensesController::class, 'restore'])->name('restore');
        });
    });

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

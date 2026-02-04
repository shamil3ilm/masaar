<?php

use App\Http\Controllers\Api\V1\Accounting\AccountController;
use App\Http\Controllers\Api\V1\Accounting\FiscalYearController;
use App\Http\Controllers\Api\V1\Accounting\JournalEntryController;
use App\Http\Controllers\Api\V1\Accounting\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Accounting Module Routes
|--------------------------------------------------------------------------
*/

// Chart of Accounts
Route::prefix('accounts')->group(function () {
    Route::get('/', [AccountController::class, 'index'])
        ->middleware('check.permission:accounting.accounts.view')
        ->name('accounts.index');

    Route::get('/flat', [AccountController::class, 'flat'])
        ->middleware('check.permission:accounting.accounts.view')
        ->name('accounts.flat');

    Route::post('/', [AccountController::class, 'store'])
        ->middleware('check.permission:accounting.accounts.create')
        ->name('accounts.store');

    Route::get('/{account}', [AccountController::class, 'show'])
        ->middleware('check.permission:accounting.accounts.view')
        ->name('accounts.show');

    Route::put('/{account}', [AccountController::class, 'update'])
        ->middleware('check.permission:accounting.accounts.update')
        ->name('accounts.update');

    Route::delete('/{account}', [AccountController::class, 'destroy'])
        ->middleware('check.permission:accounting.accounts.delete')
        ->name('accounts.destroy');

    Route::get('/{account}/ledger', [AccountController::class, 'ledger'])
        ->middleware('check.permission:accounting.accounts.view')
        ->name('accounts.ledger');
});

// Fiscal Years
Route::prefix('fiscal-years')->group(function () {
    Route::get('/', [FiscalYearController::class, 'index'])
        ->middleware('check.permission:accounting.fiscal-years.view')
        ->name('fiscal-years.index');

    Route::get('/current', [FiscalYearController::class, 'current'])
        ->middleware('check.permission:accounting.fiscal-years.view')
        ->name('fiscal-years.current');

    Route::post('/', [FiscalYearController::class, 'store'])
        ->middleware('check.permission:accounting.fiscal-years.create')
        ->name('fiscal-years.store');

    Route::get('/{fiscalYear}', [FiscalYearController::class, 'show'])
        ->middleware('check.permission:accounting.fiscal-years.view')
        ->name('fiscal-years.show');

    Route::put('/{fiscalYear}', [FiscalYearController::class, 'update'])
        ->middleware('check.permission:accounting.fiscal-years.update')
        ->name('fiscal-years.update');

    Route::post('/{fiscalYear}/set-current', [FiscalYearController::class, 'setCurrent'])
        ->middleware('check.permission:accounting.fiscal-years.update')
        ->name('fiscal-years.set-current');

    Route::post('/{fiscalYear}/close', [FiscalYearController::class, 'close'])
        ->middleware('check.permission:accounting.fiscal-years.close')
        ->name('fiscal-years.close');

    Route::delete('/{fiscalYear}', [FiscalYearController::class, 'destroy'])
        ->middleware('check.permission:accounting.fiscal-years.delete')
        ->name('fiscal-years.destroy');

    Route::post('/initialize-coa', [FiscalYearController::class, 'initializeChartOfAccounts'])
        ->middleware('check.permission:accounting.accounts.create')
        ->name('fiscal-years.initialize-coa');
});

// Journal Entries
Route::prefix('journal-entries')->group(function () {
    Route::get('/', [JournalEntryController::class, 'index'])
        ->middleware('check.permission:accounting.journals.view')
        ->name('journal-entries.index');

    Route::post('/', [JournalEntryController::class, 'store'])
        ->middleware('check.permission:accounting.journals.create')
        ->name('journal-entries.store');

    Route::get('/{journalEntry}', [JournalEntryController::class, 'show'])
        ->middleware('check.permission:accounting.journals.view')
        ->name('journal-entries.show');

    Route::put('/{journalEntry}', [JournalEntryController::class, 'update'])
        ->middleware('check.permission:accounting.journals.update')
        ->name('journal-entries.update');

    Route::delete('/{journalEntry}', [JournalEntryController::class, 'destroy'])
        ->middleware('check.permission:accounting.journals.delete')
        ->name('journal-entries.destroy');

    Route::post('/{journalEntry}/post', [JournalEntryController::class, 'post'])
        ->middleware('check.permission:accounting.journals.post')
        ->name('journal-entries.post');

    Route::post('/{journalEntry}/void', [JournalEntryController::class, 'void'])
        ->middleware('check.permission:accounting.journals.void')
        ->name('journal-entries.void');

    Route::post('/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])
        ->middleware('check.permission:accounting.journals.reverse')
        ->name('journal-entries.reverse');
});

// Reports
Route::prefix('reports')->middleware('check.permission:accounting.reports.view')->group(function () {
    Route::get('/trial-balance', [ReportController::class, 'trialBalance'])
        ->name('reports.trial-balance');

    Route::get('/balance-sheet', [ReportController::class, 'balanceSheet'])
        ->name('reports.balance-sheet');

    Route::get('/income-statement', [ReportController::class, 'incomeStatement'])
        ->name('reports.income-statement');
});

<?php

use App\Http\Controllers\Web\AdminController;
use App\Http\Controllers\Web\CustomerPortalController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Admin Dashboard Routes (CompliPay Internal)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/organizations', [AdminController::class, 'organizations'])->name('organizations');
    Route::get('/organizations/{id}', [AdminController::class, 'organizationDetail'])->name('organization.detail');
    Route::get('/queue', [AdminController::class, 'queue'])->name('queue');
    Route::get('/logs', [AdminController::class, 'logs'])->name('logs');

    // Actions
    Route::post('/queue/process', function () {
        Artisan::call('zatca:process-offline', ['--limit' => 50]);
        return back()->with('success', 'Queue processing started');
    })->name('queue.process');

    Route::post('/queue/{id}/retry', function (string $id) {
        DB::table('offline_queue')
            ->where('id', $id)
            ->update([
                'state' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
        return back()->with('success', 'Item queued for retry');
    })->name('queue.retry');
});

/*
|--------------------------------------------------------------------------
| Customer Portal Routes (TaxFly, etc.)
|--------------------------------------------------------------------------
|
| Tenant-scoped dashboard for customers to monitor their ZATCA compliance.
| In production, protect with tenant authentication middleware.
|
*/
Route::prefix('portal')->name('portal.')->group(function () {
    // Preview mode - accepts org_id as query param for demo
    Route::get('/', [CustomerPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/submissions', [CustomerPortalController::class, 'submissions'])->name('submissions');
    Route::get('/certificates', [CustomerPortalController::class, 'certificates'])->name('certificates');
    Route::get('/users/{userId}/activity', [CustomerPortalController::class, 'userActivity'])->name('user.activity');
});

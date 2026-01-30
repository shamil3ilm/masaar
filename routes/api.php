<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| CompliPay API endpoints for authentication, invoices, and ZATCA compliance.
|
*/

// Health check
Route::get('/health', fn () => response()->json(['status' => 'ok']));

/*
|--------------------------------------------------------------------------
| Auth Routes (Public)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes (Require JWT)
|--------------------------------------------------------------------------
*/
Route::middleware('jwt.auth')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // Invoices
    Route::apiResource('invoices', InvoiceController::class);

    // ZATCA Compliance
    Route::prefix('compliance/zatca')->group(function () {
        Route::post('/generate/{invoiceId}', [ComplianceController::class, 'generate']);
        Route::post('/validate/{invoiceId}', [ComplianceController::class, 'validate']);
        Route::post('/submit/{invoiceId}', [ComplianceController::class, 'submit']);
        Route::get('/status/{invoiceId}', [ComplianceController::class, 'status']);
    });
});

<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| CompliPay API endpoints for authentication, invoices, and ZATCA compliance.
|
| Authentication Methods:
| 1. JWT Token: POST /api/auth/login -> Authorization: Bearer {token}
| 2. API Key: POST /api/api-keys -> X-API-Key: {key}
|
*/

// Health check (public)
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'version' => '1.0.0',
    'timestamp' => now()->toISOString(),
]));

/*
|--------------------------------------------------------------------------
| Public Auth Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

/*
|--------------------------------------------------------------------------
| JWT Protected Routes (User Authentication)
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'rate.api'])->group(function () {

    // Auth management
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // Invoices (full CRUD)
    Route::apiResource('invoices', InvoiceController::class);

    // ZATCA Compliance
    Route::prefix('compliance/zatca')->group(function () {
        Route::post('/generate/{invoiceId}', [ComplianceController::class, 'generate']);
        Route::post('/validate/{invoiceId}', [ComplianceController::class, 'validate']);
        Route::post('/submit/{invoiceId}', [ComplianceController::class, 'submit']);
        Route::get('/status/{invoiceId}', [ComplianceController::class, 'status']);
    });

    // ZATCA Onboarding (CSID flow)
    Route::prefix('compliance/onboarding')->group(function () {
        Route::get('/status', [OnboardingController::class, 'status']);
        Route::post('/ccsid', [OnboardingController::class, 'requestCcsid']);
        Route::post('/compliance-check', [OnboardingController::class, 'runComplianceCheck']);
        Route::post('/pcsid', [OnboardingController::class, 'requestPcsid']);
    });

    // Organizations
    Route::apiResource('organizations', OrganizationController::class)->except(['destroy']);
    Route::post('/organizations/{id}/switch', [OrganizationController::class, 'switch']);

    // Webhooks management
    Route::get('/webhooks/events', [WebhookController::class, 'events']);
    Route::apiResource('webhooks', WebhookController::class);
    Route::post('/webhooks/{id}/test', [WebhookController::class, 'test']);
    Route::post('/webhooks/{id}/rotate-secret', [WebhookController::class, 'rotateSecret']);
    Route::get('/webhooks/{id}/logs', [WebhookController::class, 'logs']);

    // API Keys management
    Route::get('/api-keys/scopes', [ApiKeyController::class, 'scopes']);
    Route::apiResource('api-keys', ApiKeyController::class);
});

/*
|--------------------------------------------------------------------------
| API Key Protected Routes (Server-to-Server Authentication)
|--------------------------------------------------------------------------
|
| These routes can be accessed using an API key instead of JWT.
| Pass the key in the X-API-Key header.
|
*/
Route::middleware(['api.key', 'rate.api'])->prefix('v1')->group(function () {

    // Invoices (API key access)
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update']);
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy']);

    // ZATCA Compliance (API key access)
    Route::post('/compliance/generate/{invoiceId}', [ComplianceController::class, 'generate']);
    Route::post('/compliance/validate/{invoiceId}', [ComplianceController::class, 'validate']);
    Route::post('/compliance/submit/{invoiceId}', [ComplianceController::class, 'submit']);
    Route::get('/compliance/status/{invoiceId}', [ComplianceController::class, 'status']);

    // Webhooks (API key access)
    Route::get('/webhooks', [WebhookController::class, 'index']);
    Route::post('/webhooks', [WebhookController::class, 'store']);
    Route::get('/webhooks/{webhook}', [WebhookController::class, 'show']);
    Route::put('/webhooks/{webhook}', [WebhookController::class, 'update']);
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
});

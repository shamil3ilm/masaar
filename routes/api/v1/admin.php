<?php

use App\Http\Controllers\Api\V1\Admin\FeatureFlagController;
use App\Http\Controllers\Api\V1\Admin\ImpersonationAuditController;
use App\Http\Controllers\Api\V1\Admin\PlatformAdminController;
use App\Http\Controllers\Api\V1\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\V1\Admin\SuperAdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\SupportTicketController;
use App\Http\Controllers\Api\V1\Admin\SystemAnnouncementController;
use Illuminate\Support\Facades\Route;

// Dashboard
Route::prefix('dashboard')->group(function () {
    Route::get('/overview', [SuperAdminDashboardController::class, 'overview']);
    Route::get('/organizations', [SuperAdminDashboardController::class, 'organizationStats']);
    Route::get('/users', [SuperAdminDashboardController::class, 'userStats']);
    Route::get('/revenue', [SuperAdminDashboardController::class, 'revenueStats']);
    Route::get('/usage', [SuperAdminDashboardController::class, 'usageStats']);
    Route::get('/support', [SuperAdminDashboardController::class, 'supportStats']);
    Route::get('/signup-trend', [SuperAdminDashboardController::class, 'signupTrend']);
    Route::get('/subscription-distribution', [SuperAdminDashboardController::class, 'subscriptionDistribution']);
    Route::get('/top-organizations', [SuperAdminDashboardController::class, 'topOrganizations']);
});

// Admin management
Route::prefix('admins')->group(function () {
    Route::get('/', [PlatformAdminController::class, 'index']);
    Route::post('/', [PlatformAdminController::class, 'store']);
    Route::get('/{admin}', [PlatformAdminController::class, 'show']);
    Route::put('/{admin}', [PlatformAdminController::class, 'update']);
    Route::delete('/{admin}', [PlatformAdminController::class, 'destroy']);
});

// Organization management
Route::prefix('organizations')->group(function () {
    Route::get('/', [PlatformAdminController::class, 'listOrganizations']);
    Route::get('/{organization}', [PlatformAdminController::class, 'showOrganization']);
    Route::get('/{organization}/users', [SuperAdminDashboardController::class, 'organizationUsers']);
    Route::post('/{organization}/suspend', [PlatformAdminController::class, 'suspendOrganization']);
    Route::post('/{organization}/activate', [PlatformAdminController::class, 'activateOrganization']);
});

// User management
Route::get('/users', [PlatformAdminController::class, 'listUsers']);

// Support tickets
Route::prefix('support-tickets')->group(function () {
    Route::get('/', [SupportTicketController::class, 'index']);
    Route::get('/stats', [SupportTicketController::class, 'stats']);
    Route::get('/{ticket}', [SupportTicketController::class, 'show']);
    Route::post('/{ticket}/reply', [SupportTicketController::class, 'reply']);
    Route::post('/{ticket}/assign', [SupportTicketController::class, 'assign']);
    Route::post('/{ticket}/close', [SupportTicketController::class, 'close']);
    Route::post('/{ticket}/reopen', [SupportTicketController::class, 'reopen']);
});

// Announcements
Route::apiResource('announcements', SystemAnnouncementController::class);
Route::post('announcements/{announcement}/publish', [SystemAnnouncementController::class, 'publish']);

// Settings
Route::prefix('settings')->group(function () {
    Route::get('/', [PlatformSettingsController::class, 'index']);
    Route::put('/', [PlatformSettingsController::class, 'bulkUpdate']);
    Route::get('/{key}', [PlatformSettingsController::class, 'show']);
    Route::put('/{key}', [PlatformSettingsController::class, 'update']);
});

// Impersonation audit log
Route::prefix('impersonation-sessions')->group(function () {
    Route::get('/', [ImpersonationAuditController::class, 'index'])->name('admin.impersonation-sessions.index');
    Route::get('/{session_id}', [ImpersonationAuditController::class, 'show'])->name('admin.impersonation-sessions.show');
});

// Feature flags
Route::prefix('feature-flags')->group(function () {
    Route::get('/', [FeatureFlagController::class, 'index']);
    Route::post('/', [FeatureFlagController::class, 'store']);
    Route::get('/check/{code}', [FeatureFlagController::class, 'checkFlag']);
    Route::get('/{flag}', [FeatureFlagController::class, 'show']);
    Route::put('/{flag}', [FeatureFlagController::class, 'update']);
    Route::post('/{flag}/toggle', [FeatureFlagController::class, 'toggle']);
});

<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ImpersonationController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    // Public routes — rate-limited individually
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth.login')->name('auth.login');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth.register')->name('auth.register');

    // Password reset (public, rate-limited)
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth.login')->name('auth.forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth.login')->name('auth.reset-password');

    // Email verification (public, rate-limited)
    Route::post('email/verify', [AuthController::class, 'verifyEmail'])->middleware('throttle:auth.login')->name('auth.email.verify');
    Route::post('email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:auth.login')->name('auth.email.resend');

    // 2FA challenge verification — no JWT needed, user holds a short-lived challenge_token
    Route::post('2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:auth.login')->name('auth.2fa.verify');

    // Protected routes
    Route::middleware(['auth:api', 'validate.jwt'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('change-password', [AuthController::class, 'changePassword'])->name('auth.change-password');

        // Impersonation — end must come before {user} to prevent 'end' being matched as a user ID
        Route::post('/impersonate/end', [ImpersonationController::class, 'end'])->name('auth.impersonate.end');
        Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->name('auth.impersonate.start');

        // 2FA management (requires full JWT auth + active organization)
        Route::middleware(['check.organization'])->group(function () {
            Route::post('2fa/setup', [TwoFactorController::class, 'setup'])->name('auth.2fa.setup');
            Route::post('2fa/enable', [TwoFactorController::class, 'enable'])->name('auth.2fa.enable');
            Route::post('2fa/disable', [TwoFactorController::class, 'disable'])->name('auth.2fa.disable');
            Route::post('2fa/recovery-codes/regenerate', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('auth.2fa.recovery-codes.regenerate');
        });
    });
});

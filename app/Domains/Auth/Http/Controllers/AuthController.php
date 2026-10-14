<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Contracts\Authenticator;
use App\Domains\Auth\DTOs\LoginData;
use App\Domains\Auth\DTOs\RegistrationData;
use App\Domains\Auth\Http\Requests\LoginRequest;
use App\Domains\Auth\Http\Requests\RegisterRequest;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\AccountRegistrar;
use App\Domains\Auth\Services\SignInRecorder;
use App\Domains\Auth\Services\TwoFactor;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Authentication API controller.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly AuditService $audit,
        private readonly AccountRegistrar $registrar,
        private readonly SignInRecorder $signIns,
        private readonly TwoFactor $twoFactor,
    ) {}

    /**
     * Register a new user.
     *
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->registrar->register(RegistrationData::from($request->validated()));

        $token = $this->auth->attempt(
            LoginData::from([
                'email' => $request->email,
                'password' => $request->password,
            ])
        );

        return ApiResponse::created([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token?->toArray(),
        ], 'User registered');
    }

    /**
     * Login user and return token.
     *
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // A platform admin's second factor applies here too. Checked before a
        // token exists, so a wrong code issues nothing. Without it the API
        // was the way around the console's two-factor: the same password,
        // and an admin token.
        if (($refusal = $this->secondFactor($request)) !== null) {
            return $refusal;
        }

        $token = $this->auth->attempt(
            LoginData::from([
                'email' => $request->email,
                'password' => $request->password,
            ])
        );

        if (! $token) {
            $this->signIns->failed((string) $request->email, 'api');

            return ApiResponse::unauthorized('Invalid credentials');
        }

        $this->signIns->succeeded($this->auth->user(), 'api');

        return ApiResponse::success([
            'token' => $token->toArray(),
        ], 'Login successful');
    }

    /**
     * The refusal a platform admin's second factor calls for, or null.
     *
     * Only once the password is right: a wrong password goes on to the
     * ordinary failure, so this says nothing about whether an account is an
     * admin's to someone who does not hold its password.
     */
    private function secondFactor(LoginRequest $request): ?JsonResponse
    {
        $guard = Auth::guard('api');

        $valid = $guard->validate([
            'email' => $request->email,
            'password' => $request->password,
            'status' => 'active',
        ]);

        $user = $valid ? $guard->getLastAttempted() : null;

        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            return null;
        }

        if (! $user->hasTwoFactor()) {
            return ApiResponse::forbidden('Platform admins must set up two-factor authentication in the console before signing in to the API.');
        }

        if (! $this->twoFactor->check($user, (string) $request->input('two_factor_code', ''))) {
            $this->audit->logSecurity('mfa.failed', 'User', (string) $user->id, ['surface' => 'api']);

            return ApiResponse::unauthorized('A valid two_factor_code is required to sign in as a platform admin.');
        }

        return null;
    }

    /**
     * Logout user (invalidate token).
     *
     * POST /api/auth/logout
     */
    public function logout(): JsonResponse
    {
        $this->audit->logAuth('logout');
        $this->auth->logout();

        return ApiResponse::success(null, 'Signed out');
    }

    /**
     * Refresh token.
     *
     * POST /api/auth/refresh
     */
    public function refresh(): JsonResponse
    {
        $token = $this->auth->refresh();

        return ApiResponse::success([
            'token' => $token->toArray(),
        ], 'Token refreshed');
    }

    /**
     * Get authenticated user.
     *
     * GET /api/auth/me
     */
    public function me(): JsonResponse
    {
        $user = $this->auth->user();

        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
            ],
        ]);
    }
}

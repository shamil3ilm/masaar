<?php

namespace App\Http\Controllers\Api;

use App\Audits\AuditService;
use App\Domains\Auth\Contracts\AuthenticatesUsers;
use App\Domains\Auth\DTOs\LoginData;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication API controller.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticatesUsers $auth,
        private readonly AuditService $audit,
    ) {}

    /**
     * Register a new user.
     *
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'status' => 'active',
        ]);

        $token = $this->auth->attempt(
            LoginData::from([
                'email' => $request->email,
                'password' => $request->password,
            ])
        );

        $this->audit->logAuth('register', $user->id);

        return ApiResponse::created([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token?->toArray(),
        ], 'User registered successfully');
    }

    /**
     * Login user and return token.
     *
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $token = $this->auth->attempt(
            LoginData::from([
                'email' => $request->email,
                'password' => $request->password,
            ])
        );

        if (! $token) {
            return ApiResponse::unauthorized('Invalid credentials');
        }

        $this->audit->logAuth('login');

        return ApiResponse::success([
            'token' => $token->toArray(),
        ], 'Login successful');
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

        return ApiResponse::success(null, 'Logged out successfully');
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

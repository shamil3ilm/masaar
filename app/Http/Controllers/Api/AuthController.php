<?php

namespace App\Http\Controllers\Api;

use App\Domains\Auth\Contracts\AuthenticatesUsers;
use App\Domains\Auth\DTOs\LoginData;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication API controller.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticatesUsers $auth
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

        return response()->json([
            'message' => 'User registered successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token?->toArray(),
        ], 201);
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
            return response()->json([
                'error' => 'Invalid credentials',
            ], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'token' => $token->toArray(),
        ]);
    }

    /**
     * Logout user (invalidate token).
     *
     * POST /api/auth/logout
     */
    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Refresh token.
     *
     * POST /api/auth/refresh
     */
    public function refresh(): JsonResponse
    {
        $token = $this->auth->refresh();

        return response()->json([
            'message' => 'Token refreshed',
            'token' => $token->toArray(),
        ]);
    }

    /**
     * Get authenticated user.
     *
     * GET /api/auth/me
     */
    public function me(): JsonResponse
    {
        $user = $this->auth->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
            ],
        ]);
    }
}

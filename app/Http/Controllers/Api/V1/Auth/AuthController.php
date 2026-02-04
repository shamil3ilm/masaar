<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Core\Branch;
use App\Models\Core\Organization;
use App\Models\Core\Role;
use App\Models\User;
use App\Services\Auth\LoginAttemptService;
use App\Services\Auth\TokenBlacklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private LoginAttemptService $loginAttemptService,
        private TokenBlacklistService $tokenBlacklistService
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        // Normalize email (case-insensitive, trim whitespace)
        $email = $this->normalizeEmail($request->email);
        $ipAddress = $request->ip();

        // Check rate limiting
        $rateCheck = $this->loginAttemptService->isAllowed($email, $ipAddress);
        if (!$rateCheck['allowed']) {
            return $this->error(
                $rateCheck['message'],
                $rateCheck['reason'],
                429
            );
        }

        $credentials = [
            'email' => $email,
            'password' => $request->password,
        ];

        // Check if user exists and is active
        $user = User::withTrashed()->where('email', $email)->first();

        if (!$user) {
            $this->loginAttemptService->recordAttempt($email, $ipAddress, false);
            return $this->invalidCredentialsResponse($rateCheck['remaining_attempts'] ?? null);
        }

        // Check if soft-deleted
        if ($user->deleted_at !== null) {
            $this->loginAttemptService->recordAttempt($email, $ipAddress, false);
            return $this->invalidCredentialsResponse($rateCheck['remaining_attempts'] ?? null);
        }

        if (!$user->is_active) {
            $this->loginAttemptService->recordAttempt($email, $ipAddress, false);
            return $this->error(
                'Your account has been deactivated. Please contact support.',
                'ACCOUNT_INACTIVE',
                401
            );
        }

        // Attempt authentication
        if (!$token = auth('api')->attempt($credentials)) {
            $this->loginAttemptService->recordAttempt($email, $ipAddress, false);
            return $this->invalidCredentialsResponse($rateCheck['remaining_attempts'] ?? null);
        }

        // Record successful login
        $this->loginAttemptService->recordAttempt($email, $ipAddress, true);
        $user->recordLogin();

        return $this->respondWithToken($token, $user);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        // Normalize email
        $email = $this->normalizeEmail($request->email);

        return DB::transaction(function () use ($request, $email) {
            // Create unique slug (handle race condition)
            $baseSlug = Str::slug($request->organization_name);
            $slug = $baseSlug . '-' . Str::random(6);

            // Create organization
            $organization = Organization::create([
                'name' => trim($request->organization_name),
                'slug' => $slug,
                'country_code' => $request->country_code,
                'tax_scheme' => $this->getTaxScheme($request->country_code),
                'base_currency' => $this->getDefaultCurrency($request->country_code),
                'email' => $email,
                'is_active' => true,
                'activated_at' => now(),
            ]);

            // Create default branch (bypass global scope)
            $branch = new Branch();
            $branch->organization_id = $organization->id;
            $branch->name = 'Head Office';
            $branch->code = 'HO';
            $branch->country_code = $request->country_code;
            $branch->is_default = true;
            $branch->is_active = true;
            $branch->saveQuietly(); // Skip audit for initial creation

            // Create user
            $user = User::create([
                'organization_id' => $organization->id,
                'name' => trim($request->name),
                'email' => $email,
                'password' => $request->password, // Hashed by model cast
                'is_active' => true,
                'timezone' => $this->getDefaultTimezone($request->country_code),
            ]);

            // Attach user to branch
            $user->branches()->attach($branch->id, ['is_default' => true]);

            // Assign admin role
            $adminRole = Role::withoutGlobalScopes()
                ->where('slug', 'admin')
                ->whereNull('organization_id')
                ->first();

            if ($adminRole) {
                $user->roles()->attach($adminRole->id);
            }

            // Generate token
            $token = auth('api')->login($user);

            return $this->respondWithToken($token, $user, 'Registration successful', 201);
        });
    }

    public function me(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user) {
            return $this->unauthorized('User not found');
        }

        $user->load(['organization', 'branches', 'roles.permissions']);

        return $this->success([
            'user' => new UserResource($user),
            'permissions' => $user->getAllPermissions(),
            'default_branch' => $user->getDefaultBranch()?->only(['id', 'uuid', 'name', 'code']),
        ]);
    }

    public function refresh(): JsonResponse
    {
        try {
            // Blacklist the old token first
            $this->tokenBlacklistService->blacklistCurrentToken('refresh');

            // Get new token
            $token = auth('api')->refresh();
            $user = auth('api')->user();

            return $this->respondWithToken($token, $user, 'Token refreshed successfully');
        } catch (\Exception $e) {
            return $this->error('Token refresh failed. Please login again.', 'TOKEN_REFRESH_FAILED', 401);
        }
    }

    public function logout(): JsonResponse
    {
        try {
            // Blacklist the token so it can't be reused
            $this->tokenBlacklistService->blacklistCurrentToken('logout');

            // Invalidate in JWT
            auth('api')->logout();
        } catch (\Exception $e) {
            // Token might already be invalid, that's okay
        }

        return $this->success(null, 'Successfully logged out');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = auth('api')->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return $this->error('Current password is incorrect', 'INVALID_PASSWORD', 400);
        }

        // Update password
        $user->password = $request->new_password;
        $user->save();

        // Invalidate all tokens for this user (logout from all devices)
        $this->tokenBlacklistService->blacklistAllUserTokens($user->id, 'password_change');

        // Blacklist current token
        $this->tokenBlacklistService->blacklistCurrentToken('password_change');

        // Generate new token
        auth('api')->logout();
        $token = auth('api')->login($user);

        return $this->respondWithToken(
            $token,
            $user,
            'Password changed successfully. All other sessions have been logged out.'
        );
    }

    protected function respondWithToken(
        string $token,
        User $user,
        string $message = 'Login successful',
        int $statusCode = 200
    ): JsonResponse {
        return $this->success([
            'user' => new UserResource($user),
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ], $message, $statusCode);
    }

    protected function invalidCredentialsResponse(?int $remainingAttempts): JsonResponse
    {
        $message = 'Invalid credentials.';
        if ($remainingAttempts !== null && $remainingAttempts <= 3) {
            $message .= " {$remainingAttempts} attempt(s) remaining.";
        }

        return $this->error($message, 'INVALID_CREDENTIALS', 401);
    }

    protected function normalizeEmail(string $email): string
    {
        // Lowercase and trim whitespace
        return strtolower(trim($email));
    }

    protected function getTaxScheme(string $countryCode): string
    {
        return match ($countryCode) {
            'IN' => 'GST',
            'SA', 'AE', 'BH', 'OM', 'QA', 'KW' => 'VAT',
            default => 'NONE',
        };
    }

    protected function getDefaultCurrency(string $countryCode): string
    {
        return match ($countryCode) {
            'SA' => 'SAR',
            'AE' => 'AED',
            'IN' => 'INR',
            'QA' => 'QAR',
            'OM' => 'OMR',
            'BH' => 'BHD',
            'KW' => 'KWD',
            default => 'USD',
        };
    }

    protected function getDefaultTimezone(string $countryCode): string
    {
        return match ($countryCode) {
            'SA', 'QA', 'BH', 'KW' => 'Asia/Riyadh',
            'AE', 'OM' => 'Asia/Dubai',
            'IN' => 'Asia/Kolkata',
            default => 'UTC',
        };
    }
}

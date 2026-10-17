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
use App\Domains\Auth\Services\SignInThrottle;
use App\Domains\Auth\Services\TwoFactor;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        private readonly SignInThrottle $throttle,
    ) {}

    /**
     * Register a new user.
     *
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->registrar->register(RegistrationData::from($request->validated()));

        // Signing in waits for a verified address, on the API as in the
        // portal, so the account gets the link and no token yet: a token here
        // would be a way around the verification login asks for.
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);
        }

        return ApiResponse::created([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => null,
        ], 'User registered. Verify the email address, then sign in.');
    }

    /**
     * Login user and return token.
     *
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = (string) $request->email;

        // The same limits as the console. Here there were none past the
        // anonymous request budget, which is per address, so the API was the
        // place to guess a password from many addresses at once.
        $lockedFor = $this->throttle->passwordLockedFor($email, $request->ip());

        if ($lockedFor > 0) {
            return $this->tooManyAttempts($lockedFor);
        }

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
            $this->throttle->passwordFailed($email, $request->ip());
            $this->signIns->failed($email, 'api');

            return ApiResponse::unauthorized('Invalid credentials');
        }

        // After the password, so this says nothing about an address to
        // someone who does not hold its password. API keys are unaffected:
        // they are the company's credential, not a person's.
        if (! $this->auth->user()->hasVerifiedEmail()) {
            return ApiResponse::error(
                'Verify the email address first. POST /api/auth/email/resend sends the link again.',
                403,
                code: ErrorCode::AUTH_EMAIL_NOT_VERIFIED->value,
            );
        }

        $this->throttle->passwordSucceeded($email, $request->ip());
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

        // A platform admin always; anyone else once they turned it on.
        if (! $user instanceof User || (! $user->isPlatformAdmin() && ! $user->hasTwoFactor())) {
            return null;
        }

        if (! $user->hasTwoFactor()) {
            return ApiResponse::forbidden('Platform admins must set up two-factor authentication in the console, with an enrolment link from another admin, before signing in to the API.');
        }

        // Shared with the console's code page, so a code cannot be guessed
        // here while the console is locked. Without it a six-digit code was
        // a million requests away.
        $lockedFor = $this->throttle->codeLockedFor($user);

        if ($lockedFor > 0) {
            return $this->tooManyAttempts($lockedFor);
        }

        if (! $this->twoFactor->check($user, (string) $request->input('two_factor_code', ''))) {
            $this->throttle->codeFailed($user);
            $this->audit->logSecurity('mfa.failed', 'User', (string) $user->id, ['surface' => 'api']);

            return ApiResponse::unauthorized('A valid two_factor_code is required to sign in as a platform admin.');
        }

        $this->throttle->codeSucceeded($user);

        return null;
    }

    /**
     * The verification link again, for an account that has not used it.
     *
     * POST /api/auth/email/resend
     *
     * The same answer whether or not the address has an account, or is
     * verified already, so it tells nobody which addresses do.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $this->registrar->resendVerification((string) $request->input('email'));

        return ApiResponse::success(null, 'If that address has an unverified account, a verification link is on its way.');
    }

    private function tooManyAttempts(int $seconds): JsonResponse
    {
        return ApiResponse::error(
            'Too many sign-in attempts. Try again in '.$seconds.' seconds.',
            429,
            retryAfter: $seconds,
        );
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

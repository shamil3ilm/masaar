<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Services\SignInRecorder;
use App\Domains\Auth\Services\SignInThrottle;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session authentication for the Blade surfaces (/admin and /portal).
 *
 * The JSON API authenticates with JWT or API keys; neither produces a session,
 * so the server-rendered consoles need their own credential exchange. This
 * controller is the only place a `web` guard session is established.
 */
class SessionAuthController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SignInRecorder $signIns,
        private readonly SignInThrottle $throttle,
    ) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->to($this->landingPathFor(Auth::user()));
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $lockedFor = $this->throttle->passwordLockedFor($credentials['email'], $request->ip());

        if ($lockedFor > 0) {
            throw ValidationException::withMessages([
                'email' => __('Too many login attempts. Try again in :seconds seconds.', [
                    'seconds' => $lockedFor,
                ]),
            ]);
        }

        // `status` is part of the credential set so suspended and deleted
        // accounts cannot authenticate even with a valid password.
        //
        // Validated rather than attempted: attempt() signs the user in at
        // once, remember-me cookie and all, and a platform admin is not signed
        // in until their second factor is checked.
        $attempted = Auth::validate([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'status' => 'active',
        ]);

        if (! $attempted) {
            $this->throttle->passwordFailed($credentials['email'], $request->ip());

            Log::warning('Console login failed', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Failed attempts are the signal that distinguishes a break-in
            // from ordinary use, so they are audited alongside successes.
            $this->signIns->failed($credentials['email'], 'console');

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        $this->throttle->passwordSucceeded($credentials['email'], $request->ip());

        $user = Auth::getLastAttempted();

        // A platform admin goes on to their second factor - or to enrolling
        // one - and is signed in only once it is checked. TwoFactorController
        // finishes the sign-in from what is kept here.
        if ($user->isPlatformAdmin()) {
            $request->session()->regenerate();
            $request->session()->put(TwoFactorController::PENDING, [
                'id' => (string) $user->getAuthIdentifier(),
                'remember' => $request->boolean('remember'),
                'until' => now()->addMinutes(TwoFactorController::PENDING_MINUTES)->timestamp,
            ]);

            return redirect()->route($user->hasTwoFactor() ? 'login.two-factor' : 'login.two-factor.setup');
        }

        Auth::login($user, $request->boolean('remember'));

        // Prevents session fixation: the pre-authentication session identifier
        // must not survive the privilege change.
        $request->session()->regenerate();

        $this->signIns->succeeded($user, 'console', [
            'platform_admin' => $user->isPlatformAdmin(),
        ]);

        Log::info('Console login succeeded', [
            'user_id' => $user->getAuthIdentifier(),
            'platform_admin' => $user->isPlatformAdmin(),
            'ip' => $request->ip(),
        ]);

        return redirect()->intended($this->landingPathFor($user));
    }

    public function logout(Request $request): RedirectResponse
    {
        $userId = Auth::id();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->audit->logSecurity('logout', 'User', $userId === null ? null : (string) $userId);

        Log::info('Console logout', ['user_id' => $userId]);

        // auth/login.blade.php has rendered session('status') in a blue
        // band since it was written, and nothing ever set it. This is the
        // message it was built for.
        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    /**
     * Where a user lands when they did not request a specific page.
     */
    private function landingPathFor(mixed $user): string
    {
        return $user->isPlatformAdmin()
            ? route('admin.dashboard')
            : route('portal.dashboard');
    }
}

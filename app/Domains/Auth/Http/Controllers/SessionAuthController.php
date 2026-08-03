<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
    /**
     * Failed attempts permitted per email+IP before lockout.
     */
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 300;

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

        $throttleKey = $this->throttleKey($request, $credentials['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => __('Too many login attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                ]),
            ]);
        }

        // `status` is part of the credential set so suspended and deleted
        // accounts cannot authenticate even with a valid password.
        $attempted = Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'status' => 'active',
        ], $request->boolean('remember'));

        if (! $attempted) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            Log::warning('Console login failed', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        // Prevents session fixation: the pre-authentication session identifier
        // must not survive the privilege change.
        $request->session()->regenerate();

        $user = Auth::user();

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

        Log::info('Console logout', ['user_id' => $userId]);

        return redirect()->route('login');
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

    private function throttleKey(Request $request, string $email): string
    {
        return 'console-login:'.Str::lower($email).'|'.$request->ip();
    }
}

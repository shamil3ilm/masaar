<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\SignInRecorder;
use App\Domains\Auth\Services\SignInThrottle;
use App\Domains\Auth\Services\TwoFactor;
use App\Domains\Auth\Support\Totp;
use App\Domains\Compliance\Fatoora\Services\QrCodeRenderer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The second half of a platform admin's sign-in.
 *
 * SessionAuthController checks the password and, for a platform admin, stops
 * short of signing in: it keeps who it was under PENDING and sends them here,
 * to give a code or to enrol. Nothing before this page signs an admin in.
 */
class TwoFactorController extends Controller
{
    public const PENDING = 'mfa.pending';

    public const PENDING_MINUTES = 5;

    private const SETUP_SECRET = 'mfa.setup_secret';

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly SignInRecorder $signIns,
        private readonly AuditService $audit,
        private readonly SignInThrottle $throttle,
    ) {}

    public function showChallenge(Request $request): View|RedirectResponse
    {
        $user = $this->pending($request);

        if ($user === null || ! $user->hasTwoFactor()) {
            return redirect()->route('login');
        }

        return view('auth.two-factor');
    }

    public function challenge(Request $request): RedirectResponse
    {
        $user = $this->pending($request);

        if ($user === null || ! $user->hasTwoFactor()) {
            return redirect()->route('login');
        }

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];

        $this->refuseWhileLocked($user);

        if (! $this->twoFactor->check($user, $code)) {
            $this->throttle->codeFailed($user);

            $this->audit->logSecurity('mfa.failed', 'User', (string) $user->id, ['surface' => 'console']);

            throw ValidationException::withMessages(['code' => 'That code is not right. Try the current one from your app, or a recovery code.']);
        }

        $this->throttle->codeSucceeded($user);

        $this->complete($request, $user);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function showSetup(Request $request): View|RedirectResponse
    {
        $user = $this->pending($request);

        if ($user === null || $user->hasTwoFactor()) {
            return redirect()->route('login');
        }

        $secret = $request->session()->get(self::SETUP_SECRET) ?? Totp::secret();
        $request->session()->put(self::SETUP_SECRET, $secret);

        return view('auth.two-factor-setup', [
            'secret' => $secret,
            'qr' => app(QrCodeRenderer::class)->svg(Totp::uri($secret, (string) $user->email, (string) config('app.name', 'Masaar'))),
        ]);
    }

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $this->pending($request);
        $secret = $request->session()->get(self::SETUP_SECRET);

        if ($user === null || $user->hasTwoFactor() || ! is_string($secret)) {
            return redirect()->route('login');
        }

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];

        $this->refuseWhileLocked($user);

        $recoveryCodes = $this->twoFactor->confirm($user, $secret, $code);

        if ($recoveryCodes === null) {
            $this->throttle->codeFailed($user);

            throw ValidationException::withMessages(['code' => 'That code is not right. Check the app is showing the Masaar entry you just added.']);
        }

        $this->throttle->codeSucceeded($user);

        $this->complete($request, $user);

        // Shown once, here, and never again: they are kept encrypted and are
        // not displayable afterwards.
        return view('auth.recovery-codes', ['codes' => $recoveryCodes]);
    }

    /**
     * The admin waiting on a second factor, while the wait is still fresh.
     */
    private function pending(Request $request): ?User
    {
        return $this->twoFactor->pendingAdmin($request->session()->get(self::PENDING));
    }

    private function complete(Request $request, User $user): void
    {
        $remember = (bool) ($request->session()->get(self::PENDING)['remember'] ?? false);

        Auth::login($user, $remember);

        $request->session()->forget([self::PENDING, self::SETUP_SECRET]);

        // Session fixation: the pre-authentication id must not survive.
        $request->session()->regenerate();

        $this->signIns->succeeded($user, 'console', ['platform_admin' => true, 'mfa' => true]);
    }

    /**
     * @throws ValidationException
     */
    private function refuseWhileLocked(User $user): void
    {
        $seconds = $this->throttle->codeLockedFor($user);

        if ($seconds > 0) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Try again in '.$seconds.' seconds.',
            ]);
        }
    }
}

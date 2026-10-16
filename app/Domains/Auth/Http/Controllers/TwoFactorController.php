<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\EnrolmentLinks;
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

    /**
     * The hash of the enrolment link this browser opened. Kept as the hash,
     * so the session never holds the link itself.
     */
    public const ENROLMENT = 'mfa.enrolment';

    private const SETUP_SECRET = 'mfa.setup_secret';

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly SignInRecorder $signIns,
        private readonly AuditService $audit,
        private readonly SignInThrottle $throttle,
        private readonly EnrolmentLinks $links,
    ) {}

    /**
     * Where an enrolment link lands. It marks this browser and sends the
     * admin to sign in: the link permits enrolment, the password still says
     * who is enrolling.
     */
    public function enrol(Request $request, string $token): RedirectResponse
    {
        if ($this->links->holder($token) === null) {
            return redirect()->route('login')
                ->withErrors(['email' => 'This enrolment link is not valid. It may have been used, replaced or expired - ask another platform admin for a new one.']);
        }

        $request->session()->put(self::ENROLMENT, EnrolmentLinks::hash($token));

        // The address held the token; nothing should keep a copy of it.
        return redirect()->route('login')
            ->with('status', 'Sign in to set up two-factor authentication.')
            ->header('Cache-Control', 'no-store');
    }

    public function showChallenge(Request $request): View|RedirectResponse
    {
        $user = $this->pendingSignIn($request);

        if ($user === null || ! $user->hasTwoFactor()) {
            return redirect()->route('login');
        }

        return view('auth.two-factor');
    }

    public function challenge(Request $request): RedirectResponse
    {
        $user = $this->pendingSignIn($request);

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

        return redirect()->intended(route($user->isPlatformAdmin() ? 'admin.dashboard' : 'portal.dashboard'));
    }

    public function showSetup(Request $request): View|RedirectResponse
    {
        $user = $this->pending($request);

        if ($user === null || $user->hasTwoFactor() || ! $this->mayEnrol($request, $user)) {
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

        if ($user === null || $user->hasTwoFactor() || ! is_string($secret) || ! $this->mayEnrol($request, $user)) {
            return redirect()->route('login');
        }

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];

        $this->refuseWhileLocked($user);

        $recoveryCodes = $this->links->enrol($user, $request->session()->get(self::ENROLMENT), $secret, $code);

        if ($recoveryCodes === EnrolmentLinks::LINK_GONE) {
            return redirect()->route('login');
        }

        if ($recoveryCodes === EnrolmentLinks::WRONG_CODE) {
            $this->throttle->codeFailed($user);

            throw ValidationException::withMessages(['code' => 'That code is not right. Check the app is showing the Masaar entry you just added.']);
        }

        $this->throttle->codeSucceeded($user);
        $request->session()->forget(self::ENROLMENT);

        $this->complete($request, $user);

        // Shown once, here, and never again: they are kept encrypted and are
        // not displayable afterwards.
        return view('auth.recovery-codes', ['codes' => $recoveryCodes]);
    }

    /**
     * Whether this browser opened a live enrolment link issued to this admin.
     */
    private function mayEnrol(Request $request, User $user): bool
    {
        return $this->links->permits($user, $request->session()->get(self::ENROLMENT));
    }

    /**
     * The admin waiting on a second factor, while the wait is still fresh.
     */
    private function pending(Request $request): ?User
    {
        return $this->twoFactor->pendingAdmin($request->session()->get(self::PENDING));
    }

    /**
     * For the code itself, which anyone with a second factor is asked for;
     * enrolling through this page stays the platform admins' (pending()).
     */
    private function pendingSignIn(Request $request): ?User
    {
        return $this->twoFactor->pendingSignIn($request->session()->get(self::PENDING));
    }

    private function complete(Request $request, User $user): void
    {
        $remember = (bool) ($request->session()->get(self::PENDING)['remember'] ?? false);

        Auth::login($user, $remember);

        $request->session()->forget([self::PENDING, self::SETUP_SECRET]);

        // Session fixation: the pre-authentication id must not survive.
        $request->session()->regenerate();

        $this->signIns->succeeded($user, 'console', ['platform_admin' => $user->isPlatformAdmin(), 'mfa' => true]);
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

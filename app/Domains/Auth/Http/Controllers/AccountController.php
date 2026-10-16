<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Services\AccountDeletion;
use App\Domains\Auth\Services\AccountSettings;
use App\Domains\Auth\Services\SignInThrottle;
use App\Domains\Auth\Services\TwoFactor;
use App\Domains\Auth\Support\Totp;
use App\Domains\Compliance\Fatoora\Services\QrCodeRenderer;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * My account: the signed-in person's own name, email address, password,
 * sessions, second factor, and deleting it all.
 */
class AccountController extends Controller
{
    private const SETUP_SECRET = 'account.two_factor_secret';

    public function __construct(
        private readonly AccountSettings $settings,
        private readonly TwoFactor $twoFactor,
        private readonly AccountDeletion $deletion,
        private readonly QrCodeRenderer $qr,
        private readonly SignInThrottle $throttle,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $secret = $request->session()->get(self::SETUP_SECRET);

        return view('account.show', [
            'user' => $user,
            'setupSecret' => is_string($secret) ? $secret : null,
            'setupQr' => is_string($secret) ? $this->qr->svg(Totp::uri($secret, (string) $user->email, (string) config('app.name', 'Masaar'))) : null,
            'cannotTurnOff' => $user->hasTwoFactor() ? $this->twoFactor->mayTurnOff($user) : null,
        ]);
    }

    public function name(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $this->settings->rename($request->user(), $data['name']);

        return back()->with('success', 'Name saved.');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        return $this->attempt(fn () => $this->settings->requestEmailChange($request->user(), $data['email'], $data['password']),
            'We sent a link to '.$data['email'].'. Your address changes when you open it.');
    }

    public function confirmEmail(Request $request, string $token): RedirectResponse
    {
        try {
            $this->settings->confirmEmailChange($request->user(), $token);
        } catch (DomainException $e) {
            return redirect()->route('account.show')->with('error', $e->getMessage());
        }

        return redirect()->route('account.show')->with('success', 'Your email address is changed.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        return $this->attempt(fn () => $this->settings->changePassword($request->user(), $data['current_password'], $data['password']),
            'Password changed. You are signed out everywhere else.');
    }

    public function sessions(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        return $this->attempt(fn () => $this->settings->signOutOtherSessions($request->user(), $data['password']),
            'Signed out of every other browser and device.');
    }

    /**
     * Turning a second factor on, step one: a secret to scan.
     */
    public function twoFactorStart(Request $request): RedirectResponse
    {
        if (! $request->user()->hasTwoFactor()) {
            $request->session()->put(self::SETUP_SECRET, Totp::secret());
        }

        return redirect()->to(route('account.show').'#two-factor');
    }

    /**
     * Step two: a code from the app, proving it holds the secret.
     */
    public function twoFactorConfirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string'],
        ]);
        $user = $request->user();
        $secret = $request->session()->get(self::SETUP_SECRET);

        if (! is_string($secret) || $user->hasTwoFactor()) {
            return redirect()->route('account.show');
        }

        // Someone holding the session could otherwise bind their own phone
        // and lock the owner out.
        if (! Hash::check((string) $data['password'], (string) $user->password)) {
            return back()->with('error', 'Your current password is not right.');
        }

        if (($lockedFor = $this->throttle->codeLockedFor($user)) > 0) {
            return back()->with('error', 'Too many codes tried. Try again in '.$lockedFor.' seconds.');
        }

        $codes = $this->twoFactor->confirm($user, $secret, (string) $data['code']);

        if ($codes === null) {
            $this->throttle->codeFailed($user);

            return back()->with('error', 'That code is not right. Check the app shows the entry you just added.');
        }

        $this->throttle->codeSucceeded($user);

        $request->session()->forget(self::SETUP_SECRET);

        return redirect()->to(route('account.show').'#two-factor')
            ->with('success', 'Two-factor is on. Keep these recovery codes somewhere safe; they are shown only now.')
            ->with('recovery_codes', $codes);
    }

    public function twoFactorOff(Request $request): RedirectResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];

        return $this->attempt(fn () => $this->twoFactor->turnOff($request->user(), $code), 'Two-factor is off.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirm' => ['required', 'in:DELETE'],
        ], ['confirm.in' => 'Type DELETE to confirm.']);

        try {
            $this->deletion->delete($request->user(), $data['password']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your account is deleted.');
    }

    /**
     * @param  callable(): void  $change
     */
    private function attempt(callable $change, string $success): RedirectResponse
    {
        try {
            $change();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}

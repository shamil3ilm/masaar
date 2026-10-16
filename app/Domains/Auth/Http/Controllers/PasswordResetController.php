<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * A forgotten password, replaced through a link sent to the account's email.
 *
 * There was no way back for someone who forgot theirs short of a platform
 * admin. The answer to a request is the same whether or not the address has
 * an account, so the form cannot be used to find out who does.
 */
class PasswordResetController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', __('If that address has an account, a link to reset its password is on its way.'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                // A new remember token as well, so a stolen remember-me cookie
                // stops working with the old password.
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                ])->save();

                $this->audit->logSecurity('password.reset', 'User', (string) $user->id, userId: (string) $user->id);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('That reset link is not valid any more. Ask for a new one.')]);
        }

        return redirect()->route('login')->with('status', __('Your password is changed. Sign in with it.'));
    }
}

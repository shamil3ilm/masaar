<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\SignupRequest;
use App\Domains\Auth\Services\SelfSignup;
use App\Domains\Auth\Services\SignInRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * A business signs itself up from the browser.
 *
 * It leaves signed in, with a link on its way to prove the email address,
 * and a company waiting for a platform admin to review it.
 */
class SignupController extends Controller
{
    public function __construct(
        private readonly SelfSignup $signup,
        private readonly SignInRecorder $signIns,
    ) {}

    public function create(): View|RedirectResponse
    {
        abort_unless((bool) config('signup.enabled'), 404);

        if (Auth::check()) {
            return redirect()->route('portal.dashboard');
        }

        return view('auth.register', [
            'termsUrl' => config('signup.terms_url'),
        ]);
    }

    public function store(SignupRequest $request): RedirectResponse
    {
        abort_unless((bool) config('signup.enabled'), 404);

        $user = $this->signup->register($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        $this->signIns->succeeded($user, 'console', ['signup' => true]);

        return redirect()->route('verification.notice');
    }
}

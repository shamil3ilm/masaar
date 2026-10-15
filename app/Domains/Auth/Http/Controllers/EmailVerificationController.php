<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Proving an email address belongs to the person who signed up with it.
 *
 * The portal waits for it: password resets and every notice about the
 * company's filing go to that address, so it has to be theirs.
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('portal.dashboard')
            : view('auth.verify-email');
    }

    /**
     * The link from the email. Signed, and for the signed-in user's own
     * address only - EmailVerificationRequest checks both.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail() && $request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->route('portal.dashboard')->with('success', 'Your email address is verified.');
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('portal.dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A new verification link is on its way.');
    }
}

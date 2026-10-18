<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Services\AdminInvitations;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Accepting an invitation to be a platform admin: signed in as the invited
 * address, or by making the account it is for. Either way the next stop is
 * the one-time link that enrols a second factor.
 */
class AdminInvitationController extends Controller
{
    public function __construct(
        private readonly AdminInvitations $invitations,
    ) {}

    public function show(string $token): View|RedirectResponse
    {
        $invitation = $this->invitations->find($token);

        if ($invitation === null) {
            return redirect()->route('login')->withErrors(['email' => __('This invitation is not valid any more. Ask for a new one.')]);
        }

        return view('auth.admin-invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'hasAccount' => $this->invitations->hasAccount($invitation->email),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->find($token) ?? abort(404);

        try {
            $link = $this->invitations->accept($invitation, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->next($link);
    }

    public function join(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->find($token) ?? abort(404);

        abort_if($this->invitations->hasAccount($invitation->email), 409, __('That address already has an account. Sign in to accept.'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
            'terms' => ['accepted'],
        ]);

        return $this->next($this->invitations->join($invitation, $data['name'], $data['password']));
    }

    /**
     * To the enrolment link, or - for someone with a second factor already -
     * to sign in.
     */
    private function next(?string $link): RedirectResponse
    {
        return $link !== null
            ? redirect()->to($link)->with('success', __('You are a platform admin now. Set up two-factor sign-in to open the console.'))
            : redirect()->route('login')->with('success', __('You are a platform admin now. Sign in to open the console.'));
    }
}

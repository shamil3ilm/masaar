<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\Team;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Where an invitation link lands.
 *
 * Signed in as the invited address, accepting is one click. Signed out, an
 * address that already has an account signs in and comes back; one that does
 * not creates an account here - verified already, since the link reached it.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly Team $team,
    ) {}

    public function show(Request $request, string $token): View|RedirectResponse
    {
        $invitation = $this->team->invitation($token);

        if ($invitation === null) {
            return redirect()->route('login')->withErrors(['email' => __('This invitation is not valid any more. Ask for a new one.')]);
        }

        return view('auth.invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'hasAccount' => $this->team->hasAccount($invitation->email),
        ]);
    }

    /**
     * Accept, signed in.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->team->invitation($token) ?? abort(404);

        try {
            $this->team->accept($invitation, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $request->session()->put(PortalTenant::SESSION_KEY, $invitation->org_id);

        return redirect()->route('portal.dashboard')->with('success', __('You have joined :organization.', ['organization' => $invitation->organization->name]));
    }

    /**
     * Accept by creating the account the invitation is for.
     */
    public function join(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->team->invitation($token) ?? abort(404);

        abort_if($this->team->hasAccount($invitation->email), 409, __('That address already has an account. Sign in to accept.'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
            'terms' => ['accepted'],
        ]);

        $user = $this->team->join($invitation, $data['name'], $data['password']);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(PortalTenant::SESSION_KEY, $invitation->org_id);

        return redirect()->route('portal.dashboard')->with('success', __('Welcome to :organization.', ['organization' => rtrim((string) $invitation->organization->name, '.')]));
    }
}

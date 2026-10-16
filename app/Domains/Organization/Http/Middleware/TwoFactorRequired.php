<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Middleware;

use App\Domains\Organization\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An organization that requires a second factor gets one from everyone.
 *
 * A member without one is sent to My account to set it up, and the portal
 * waits until they have. Checked on every request rather than at sign-in, so
 * turning the requirement on applies to people already signed in.
 */
class TwoFactorRequired
{
    public function __construct(
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasTwoFactor() || ! $this->tenant->getOrganization()?->require_two_factor) {
            return $next($request);
        }

        return redirect()->to(route('account.show').'#two-factor')
            ->with('error', __(':organization requires two-factor authentication. Set it up to continue.', ['organization' => $this->tenant->getOrganization()->name]));
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Http\Responses\ApiResponse;
use App\Support\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * access.person:issue_invoices - a platform admin's switch for the person's
 * role, or for the person, holds on the API too.
 *
 * The API's issuing routes ask everything else their own way and answer with
 * their own error codes, so this asks only the levels they do not: the
 * person's (Access::personDecision). The portal asks Access whole.
 */
class PersonAccess
{
    public function __construct(
        private readonly Access $access,
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $organization = $this->tenant->getOrganization();
        $user = $request->user();

        if ($organization !== null && $user instanceof User) {
            $decision = $this->access->personDecision($organization, $user, Feature::from($feature));

            if ($decision !== null) {
                return ApiResponse::error($decision->reason, 403, ['feature' => $feature], ErrorCode::AUTH_FEATURE_SWITCHED_OFF->value);
            }
        }

        return $next($request);
    }
}

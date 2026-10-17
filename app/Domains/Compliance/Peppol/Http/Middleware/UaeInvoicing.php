<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Http\Middleware;

use App\Domains\Organization\Services\TenantResolver;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UAE e-invoicing, for a UAE organization.
 *
 * An organization registered elsewhere is refused: its invoices are filed by
 * its own country's rules, and a UAE document from it would be neither.
 *
 * Whether the deployment issues in the UAE at all is the engine's answer, not
 * this middleware's: the routes that start new work also carry
 * jurisdiction.available, as Saudi Arabia's do, so a country switched off
 * answers 422 JURISDICTION_UNAVAILABLE in both, and the reads stay open.
 */
class UaeInvoicing
{
    public function __construct(
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenant->getOrganization()?->country !== 'AE') {
            return ApiResponse::forbidden(__('UAE e-invoicing is for an organization registered in the UAE.'));
        }

        return $next($request);
    }
}

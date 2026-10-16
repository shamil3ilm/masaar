<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Http\Middleware;

use App\Domains\Organization\Services\TenantResolver;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UAE e-invoicing, for a UAE organization on a deployment that offers it.
 *
 * Until PEPPOL_AE_ENABLED is on the routes answer as if they did not exist,
 * rather than accepting work nobody has set up to carry. Where it is on, an
 * organization registered elsewhere is refused: its invoices are filed by its
 * own country's rules, and a UAE document from it would be neither.
 */
class UaeInvoicing
{
    public function __construct(
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('peppol.countries.AE.enabled'), 404);

        if ($this->tenant->getOrganization()?->country !== 'AE') {
            return ApiResponse::forbidden(__('UAE e-invoicing is for an organization registered in the UAE.'));
        }

        return $next($request);
    }
}

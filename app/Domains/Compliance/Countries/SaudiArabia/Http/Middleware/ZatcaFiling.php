<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Middleware;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The licence-key API's step-by-step ZATCA routes (/api/v1/compliance/*), for
 * an organization registered in Saudi Arabia. Attached by ComplianceController
 * itself, so no shared route file names this module more than it did.
 *
 * Those routes are this module's controller whoever calls them, so an
 * organization registered in another country was answered with ZATCA's
 * behaviour - a Saudi document generated, validated or filed for it. Until
 * the engine routes them by country, such an organization is refused with
 * 422 and told where its invoices go: the pipeline, which every country's
 * invoices go through.
 */
class ZatcaFiling
{
    /** The country whose invoices these routes file. */
    private const COUNTRY = 'SA';

    /** The licence-key API, whose callers are pointed at the pipeline. */
    private const PARTNER_API = 'api/v1/*';

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->tenant->getOrganization();
        $jurisdiction = $this->jurisdictions->forRules($organization);

        if (! $request->is(self::PARTNER_API) || $organization === null || $jurisdiction->code() === self::COUNTRY) {
            return $next($request);
        }

        return ApiResponse::error(
            __('These routes file with ZATCA, for an organization registered in Saudi Arabia. This organization is registered in :country: issue and send its invoices with POST /api/v1/pipeline/submit (or /api/v1/pipeline/batch), and follow them with GET /api/v1/pipeline/status/{invoiceId}.', ['country' => $jurisdiction->name()]),
            422,
            ['use' => ['POST /api/v1/pipeline/submit', 'POST /api/v1/pipeline/batch', 'GET /api/v1/pipeline/status/{invoiceId}']],
            'JURISDICTION_ENDPOINT_MISMATCH',
        );
    }
}

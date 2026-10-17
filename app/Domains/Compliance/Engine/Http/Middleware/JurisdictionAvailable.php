<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Http\Middleware;

use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Organization\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A country's own API routes that start new work - issuing, filing, sending -
 * for an organization whose country this deployment issues in.
 *
 * Every country's write routes carry it, so a country switched off answers
 * the same everywhere: 422 JURISDICTION_UNAVAILABLE (bootstrap/app.php), as
 * the pipeline does. Reads stay open, so an ERP can still follow what was
 * issued before the switch.
 */
class JurisdictionAvailable
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly ComplianceEngine $engine,
    ) {}

    /**
     * @throws JurisdictionUnavailableException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->tenant->getOrganization();

        if ($organization !== null) {
            $this->engine->jurisdictionFor($organization);
        }

        return $next($request);
    }
}

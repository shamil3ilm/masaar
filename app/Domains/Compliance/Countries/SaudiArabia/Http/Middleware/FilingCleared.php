<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Middleware;

use App\Domains\Organization\Services\FilingClearance;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Responses\ApiResponse;
use App\Support\Errors\ComplianceException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ZATCA onboarding, for an organization cleared to deal with ZATCA.
 *
 * An OTP spent on a company that has not been approved buys a certificate it
 * cannot use, and the OTP is gone; refused before the request is made.
 */
class FilingCleared
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly FilingClearance $clearance,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->tenant->getOrganization();

        // No organization to clear is a refusal, not a pass.
        if ($organization === null) {
            return ApiResponse::forbidden('Switch to an organization first.');
        }

        try {
            $this->clearance->assertCleared($organization);
        } catch (ComplianceException $e) {
            return ApiResponse::fromFatoora($e);
        }

        return $next($request);
    }
}

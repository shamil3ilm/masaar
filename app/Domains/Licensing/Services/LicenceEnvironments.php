<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Organization\Models\Organization;

/**
 * The environment a licence starts in: the one the deployment files in.
 *
 * Where documents go is the deployment's configuration (Transmission::live):
 * the authority's production or its sandbox, a network's provider or its
 * simulator. A licence issued on a deployment that files live is a
 * production licence unless someone chose otherwise - a sandbox one would
 * be refused the moment it filed (SandboxGuard). Licences issued from the
 * console were all sandbox ones, because nothing gave them an environment.
 */
class LicenceEnvironments
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly JurisdictionRegistry $registry,
    ) {}

    /**
     * For a licence acting for this organization: production where its
     * country files live. Without an organization, the deployment's.
     */
    public function defaultFor(?Organization $organization): LicenseEnvironment
    {
        if ($organization === null) {
            return $this->deployment();
        }

        return $this->jurisdictions->forRules($organization)->transmission()->live($organization)
            ? LicenseEnvironment::Production
            : LicenseEnvironment::Sandbox;
    }

    /**
     * Production where any country this deployment issues in files live,
     * so a licence not yet tied to an organization is not refused there.
     */
    public function deployment(): LicenseEnvironment
    {
        foreach ($this->registry->enabled() as $code) {
            if ($this->registry->get($code)->transmission()->live()) {
                return LicenseEnvironment::Production;
            }
        }

        return LicenseEnvironment::Sandbox;
    }
}

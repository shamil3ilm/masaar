<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Audit\Attribution\Origin;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Organization\Models\Organization;

/**
 * A sandbox key never files anything real.
 *
 * Which authority or network documents go to is the deployment's
 * configuration, not the request's: ZATCA_ENVIRONMENT picks the authority's
 * sandbox, simulation or production, and PEPPOL_GATEWAY=simulator answers
 * for the network (production refuses it). So a sandbox key uses those paths
 * on a deployment configured for them, and where the organization's country
 * files live (Transmission::live) it is refused before anything is issued -
 * issuing alone seals a document into the organization's real chain.
 *
 * A licence's own key keeps what it had: its environment is the partner's
 * to manage, and the authority's own check refuses a sandbox licence where
 * it would file to production.
 *
 * Asked at the partner API's issuing and filing routes (RefuseSandboxFiling)
 * and again by IssuingLifecycle, which every country's issuing passes
 * through, so a path added later is held too. The credential is read from
 * Origin, which carries it into a queued job.
 */
class SandboxGuard
{
    public function __construct(
        private readonly Origin $origin,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * Whether the credential acting now is a sandbox one and the
     * organization's documents would go somewhere live.
     */
    public function refuses(?Organization $organization): bool
    {
        if ($this->origin->apiKeyEnvironment() !== LicenseEnvironment::Sandbox->value) {
            return false;
        }

        return $this->jurisdictions->forRules($organization)->transmission()->live();
    }

    /**
     * @throws LicenseException
     */
    public function assertMayFile(?Organization $organization): void
    {
        if ($this->refuses($organization)) {
            throw LicenseException::environmentMismatch(LicenseEnvironment::Sandbox->value, LicenseEnvironment::Production->value);
        }
    }
}

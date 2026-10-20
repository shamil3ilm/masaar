<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A platform admin moves an organization to another ZATCA environment.
 *
 * Simulation and production each onboard a device afresh, with an OTP from
 * that environment's Fatoora portal, and issue their own CSIDs (E-Invoicing
 * Detailed Guidelines: onboarding). So a switch leaves the organization not
 * onboarded in the new one: what it and its tills held is archived, never
 * deleted (CredentialStore::archive(); records are kept, Detailed
 * Guidelines p26, p63), its tills are back to pending, and the onboarding
 * flow asks for the new environment's OTP. Audited with the admin's id.
 */
class EnvironmentSwitch
{
    public const AUDIT_ACTION = 'zatca.environment.changed';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly AuditService $audit,
        private readonly Tills $tills,
    ) {}

    /** A Saudi organization, for the console's page; null for any other. */
    public function organization(string $id): ?Organization
    {
        $organization = Organization::query()->find($id);

        return $organization?->country === 'SA' ? $organization : null;
    }

    /**
     * @throws DomainException for an environment ZATCA does not have
     */
    public function set(Organization $organization, string $environment, ?string $by): void
    {
        if (! in_array($environment, FatooraEnvironment::ALL, true)) {
            throw new DomainException(__('Unknown ZATCA environment: :environment.', ['environment' => $environment]));
        }

        DB::transaction(function () use ($organization, $environment, $by): void {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $from = FatooraEnvironment::of($locked);

            if ($from === $environment && FatooraEnvironment::chosen($locked)) {
                return;
            }

            $archived = $from === $environment ? 0 : $this->leave($locked, $from);

            $locked->update(['compliance_profile' => array_merge($locked->compliance_profile ?? [], [
                FatooraEnvironment::PROFILE_KEY => $environment,
            ], $from === $environment ? [] : ['zatca_onboarded' => false, 'onboarded_at' => null])]);

            $this->audit->logChange(
                self::AUDIT_ACTION,
                'Organization',
                (string) $locked->id,
                ['environment' => $from],
                ['environment' => $environment],
                ['archived_credentials' => $archived],
                $by,
                (string) $locked->id,
            );

            $organization->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Archive what the organization holds and put every till back to
     * pending: nothing it was issued in $from signs or files in another.
     */
    private function leave(Organization $organization, string $from): int
    {
        $id = (string) $organization->id;
        $archived = $this->credentials->archive($id, $from);

        $this->tills->resetOnboarding($id);

        // The certificate versions follow the files: none is active now.
        $this->credentials->versions($id);
        foreach ($this->tills->all($id) as $unit) {
            if (! $unit->isHeadOffice()) {
                $this->credentials->versions($id, (string) $unit->id);
            }
        }

        return $archived;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\UnfiledDocuments;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
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

    public const REFUSED_ACTION = 'zatca.environment.refused';

    /** When the organization entered its current environment. */
    public const SINCE_KEY = 'zatca_environment_since';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly AuditService $audit,
        private readonly Tills $tills,
        private readonly UnfiledDocuments $unfiled,
    ) {}

    /** A Saudi organization, for the console's page; null for any other. */
    public function organization(string $id): ?Organization
    {
        $organization = Organization::query()->find($id);

        return $organization?->country === 'SA' ? $organization : null;
    }

    /**
     * What it signed in its current environment that ZATCA does not hold
     * yet: a switch now would leave it unfilable.
     *
     * @return Collection<int, array{id: string, invoice_number: string, reason: string}>
     */
    public function waiting(Organization $organization): Collection
    {
        $since = $organization->compliance_profile[self::SINCE_KEY] ?? null;

        return $this->unfiled->inOrganization((string) $organization->id, $since === null ? null : CarbonImmutable::parse($since));
    }

    /**
     * Refused while documents signed in the current environment are unfiled
     * (waiting()): the new environment's gateway cannot file them, and the
     * old one's credentials are archived by the switch. Audited either way.
     *
     * @throws DomainException for an environment ZATCA does not have, or documents left to file
     */
    public function set(Organization $organization, string $environment, ?string $by): void
    {
        if (! in_array($environment, FatooraEnvironment::ALL, true)) {
            throw new DomainException(__('Unknown ZATCA environment: :environment.', ['environment' => $environment]));
        }

        // Read under the row's lock, refused after it: the refusal's audit
        // entry must not roll back with the switch it refused.
        $refused = DB::transaction(function () use ($organization, $environment, $by): ?array {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $from = FatooraEnvironment::of($locked);

            if ($from === $environment && FatooraEnvironment::chosen($locked)) {
                return null;
            }

            if ($from !== $environment && ($waiting = $this->waiting($locked))->isNotEmpty()) {
                return ['from' => $from, 'waiting' => $waiting];
            }

            $archived = $from === $environment ? 0 : $this->leave($locked, $from);

            $locked->update(['compliance_profile' => array_merge($locked->compliance_profile ?? [], [
                FatooraEnvironment::PROFILE_KEY => $environment,
            ], $from === $environment ? [] : ['zatca_onboarded' => false, 'onboarded_at' => null, self::SINCE_KEY => now()->toIso8601String()])]);

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

            return null;
        });

        if ($refused !== null) {
            $this->refuse($organization, $refused['from'], $environment, $refused['waiting'], $by);
        }
    }

    /**
     * @param  Collection<int, array{id: string, invoice_number: string, reason: string}>  $waiting
     *
     * @throws DomainException
     */
    private function refuse(Organization $organization, string $from, string $to, Collection $waiting, ?string $by): never
    {
        $this->audit->logChange(self::REFUSED_ACTION, 'Organization', (string) $organization->id, ['environment' => $from], ['environment' => $to], [
            'unfiled' => $waiting->pluck('invoice_number')->all(),
        ], $by, (string) $organization->id);

        throw new DomainException(trans_choice(
            'One document signed in :environment has not been filed with ZATCA yet: :list. File it before switching environment.|:count documents signed in :environment have not been filed with ZATCA yet: :list. File them before switching environment.',
            $waiting->count(),
            ['count' => $waiting->count(), 'environment' => $from, 'list' => $waiting->take(UnfiledDocuments::LISTED)->pluck('invoice_number')->implode(', ')],
        ));
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

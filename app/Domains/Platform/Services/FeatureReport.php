<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Decision;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;

/**
 * The Features section of the admin console's organization page: every
 * feature with what Access answers for the organization, the level that
 * decided, what governs it, and the platform admin's switch if there is one.
 */
class FeatureReport
{
    public function __construct(
        private readonly Access $access,
        private readonly FeatureOverrides $overrides,
    ) {}

    /**
     * A platform admin's switches for the organization's roles and people:
     * the subject ('role:member', 'user:{id}'), the feature and the switch.
     *
     * @return list<array{subject: string, feature: Feature, override: array{state: string, reason: string, by: string|null, at: string|null}}>
     */
    public function people(string $organizationId): array
    {
        $rows = [];

        foreach ($this->overrides->forPeople($organizationId) as $subject => $switches) {
            foreach ($switches as $value => $switch) {
                $feature = Feature::tryFrom((string) $value);

                if ($feature !== null) {
                    $rows[] = ['subject' => (string) $subject, 'feature' => $feature, 'override' => $switch];
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<array{feature: Feature, decision: Decision, override: array{state: string, reason: string, by: string|null, at: string|null}|null, governed: list<string>}>
     */
    public function rows(string $organizationId): array
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            return [];
        }

        $switches = $this->overrides->for($organizationId);
        $rows = [];

        foreach ($this->access->all($organization) as $value => $decision) {
            $feature = Feature::from($value);

            $rows[] = [
                'feature' => $feature,
                'decision' => $decision,
                'override' => $switches[$value] ?? null,
                'governed' => $this->governed($feature),
            ];
        }

        return $rows;
    }

    /**
     * The existing mechanisms the feature answers to, as support reads them.
     *
     * @return list<string>
     */
    private function governed(Feature $feature): array
    {
        $governed = [];

        if ($feature->killSwitches() !== []) {
            $governed[] = 'Kill switch: '.implode(', ', $feature->killSwitches());
        }

        $governed[] = $feature->startsFiling() ? 'Country switch and module' : 'Country module';

        if ($feature->licenceFeature() !== null) {
            $governed[] = 'Plan feature: '.$feature->licenceFeature();
        }

        if ($feature->needsLicenceKey()) {
            $governed[] = 'Licence key';
        }

        if ($feature->startsFiling()) {
            $governed[] = 'Company review status';
        }

        if ($feature->minimumRole() !== null) {
            $governed[] = 'Role: '.$feature->minimumRole().' or above';
        }

        return $governed;
    }
}

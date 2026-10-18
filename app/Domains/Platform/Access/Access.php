<?php

declare(strict_types=1);

namespace App\Domains\Platform\Access;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Licensing\Enums\ApiScope;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseValidationService;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use App\Domains\Platform\Models\FeatureOverride;
use App\Domains\Platform\Services\KillSwitch;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;

/**
 * May this organization - and this person in it - use this feature, in its
 * country, right now; and if not, why?
 *
 * One answer over the switches that already exist, asked in a fixed order;
 * the first level that says no decides (docs/architecture/ACCESS.md):
 *
 *   platform      a global kill switch
 *   country       no module for the country, the country switched off, or
 *                 the feature not part of it
 *   plan          the licence: its features, its scopes, or having none
 *   organization  in review, suspended or turned down; its own kill switch;
 *                 a platform admin's switch for it
 *   role          the person's role in the organization (none for the API),
 *                 and a platform admin's switch for that role in it
 *   user          a platform admin's switch for the one person
 *
 * Nothing here is a new switch except FeatureOverrides. Each level reads the
 * mechanism that already decides - KillSwitch, the country's module,
 * LicenseValidationService, FilingClearance, the membership - so Access and
 * the checks the issuing paths still make cannot disagree.
 */
class Access
{
    /**
     * The person's level alone, for a path that has already asked the rest
     * its own way: the API's issuing routes (access.person). Null when the
     * person's role and the person are not switched off.
     */
    public function personDecision(Organization $organization, User $user, Feature $feature): ?Decision
    {
        return $this->people($organization, $user, $feature);
    }

    public function __construct(
        private readonly KillSwitch $killSwitch,
        private readonly JurisdictionRegistry $registry,
        private readonly LicenseValidationService $licences,
        private readonly FilingClearance $clearance,
        private readonly FeatureOverrides $overrides,
    ) {}

    public function decide(Organization $organization, ?User $user, Feature $feature): Decision
    {
        $jurisdiction = $this->jurisdiction($organization);
        $switches = $this->overrides->for((string) $organization->id);

        return $this->platform($feature, $jurisdiction)
            ?? $this->country($organization, $feature, $jurisdiction)
            ?? $this->plan($organization, $feature, $switches)
            ?? $this->organization($organization, $feature, $jurisdiction, $switches)
            ?? $this->role($organization, $user, $feature)
            ?? $this->people($organization, $user, $feature)
            ?? $this->allowed($feature, $switches);
    }

    public function allows(Organization $organization, ?User $user, Feature $feature): bool
    {
        return $this->decide($organization, $user, $feature)->allowed;
    }

    /**
     * Every feature's answer for the organization, with no person: the admin
     * console's view of it.
     *
     * @return array<string, Decision> by feature value, in catalogue order
     */
    public function all(Organization $organization): array
    {
        $decisions = [];

        foreach (Feature::cases() as $feature) {
            $decisions[$feature->value] = $this->decide($organization, null, $feature);
        }

        return $decisions;
    }

    /**
     * The plan level alone, for one licence: the partner API's feature check
     * (RequireLicenseFeature). The licence's features decide, unless a
     * platform admin granted this one to its organization beyond the plan.
     *
     * Only the plan: the API refuses a missing feature with its own error
     * code before anything else is asked, as it always has.
     */
    public function licenceAllows(License $licence, Feature $feature): bool
    {
        if ($this->grantedBeyondPlan($licence->org_id === null ? null : (string) $licence->org_id, $feature)) {
            return true;
        }

        $key = $feature->licenceFeature();

        return $key === null || $this->licences->hasFeature($licence, $key);
    }

    private function platform(Feature $feature, ?Jurisdiction $jurisdiction): ?Decision
    {
        foreach ($this->killSwitches($feature, $jurisdiction) as $switch) {
            if (! $this->killSwitch->isEnabled($switch)) {
                continue;
            }

            $info = $this->killSwitch->getSwitchInfo($switch) ?? [];

            return Decision::refuse($feature, Level::Platform, 'kill_switch',
                __('Paused on the whole platform by an operator: :reason', ['reason' => $info['reason'] ?? __('no reason given.')]),
                ['kill_switch' => $switch, 'reason' => $info['reason'] ?? null, 'enabled_at' => $info['enabled_at'] ?? null, 'expires_at' => $info['expires_at'] ?? null]);
        }

        return null;
    }

    private function country(Organization $organization, Feature $feature, ?Jurisdiction $jurisdiction): ?Decision
    {
        if ($jurisdiction === null) {
            return Decision::refuse($feature, Level::Country, 'no_module',
                __('This platform has no e-invoicing for the country this company is registered in.'),
                ['country' => $organization->country]);
        }

        $detail = ['country' => $jurisdiction->code()];
        $name = __($jurisdiction->name());

        if ($feature->startsFiling() && ! $jurisdiction->enabled()) {
            return Decision::refuse($feature, Level::Country, 'country_off',
                __('E-invoicing in :country is switched off on this platform.', ['country' => $name]), $detail);
        }

        if (! $jurisdiction->features()->supportsFeature($feature)) {
            return Decision::refuse($feature, Level::Country, 'not_in_country',
                __(':feature is not part of e-invoicing in :country.', ['feature' => $feature->label(), 'country' => $name]), $detail);
        }

        return null;
    }

    /**
     * @param  array<string, array{state: string}>  $switches
     */
    private function plan(Organization $organization, Feature $feature, array $switches): ?Decision
    {
        $licences = $this->licences->organizationLicences((string) $organization->id);

        if ($licences->isEmpty()) {
            return $this->unlicensed($organization, $feature);
        }

        // Granted beyond the plan lifts the plan's feature list, not having
        // a plan at all.
        $granted = $feature->canGrantBeyondPlan() && ($switches[$feature->value]['state'] ?? null) === FeatureOverride::ON;
        $key = $feature->licenceFeature();

        if ($key !== null && ! $granted && ! $licences->contains(fn (License $licence): bool => $this->licences->hasFeature($licence, $key))) {
            $tiers = $licences->map(fn (License $licence): string => $licence->tier->getDisplayName())->unique()->implode(', ');

            return Decision::refuse($feature, Level::Plan, 'plan_excludes',
                __('The :plan plan does not include :feature.', ['plan' => $tiers, 'feature' => $feature->label()]),
                ['licence_feature' => $key, 'tiers' => $tiers]);
        }

        if ($feature === Feature::ErpPipeline && ! $licences->contains(fn (License $licence): bool => $licence->hasScope(ApiScope::InvoiceSubmit))) {
            return Decision::refuse($feature, Level::Plan, 'scope_missing',
                __('No licence key of this company may submit invoices (scope :scope).', ['scope' => ApiScope::InvoiceSubmit->value]),
                ['scope' => ApiScope::InvoiceSubmit->value]);
        }

        return null;
    }

    /**
     * No licence is what it has always been: the partner API cannot be
     * reached without a key, and issuing needs a plan only where this
     * deployment says so - and then only once the company is approved, since
     * a company in review is given its plan when it is approved and the
     * organization level says why it waits.
     */
    private function unlicensed(Organization $organization, Feature $feature): ?Decision
    {
        if ($feature->needsLicenceKey()) {
            return Decision::refuse($feature, Level::Plan, 'no_licence',
                __('The company has no licence key, so it has no API access.'));
        }

        if (! $feature->issues() || $organization->status !== Organization::STATUS_ACTIVE) {
            return null;
        }

        try {
            $this->clearance->assertLicensed($organization);
        } catch (ComplianceException $e) {
            return Decision::refuse($feature, Level::Plan, 'no_plan', $e->getMessage());
        }

        return null;
    }

    /**
     * @param  array<string, array{state: string, reason: string, by: string|null, at: string|null}>  $switches
     */
    private function organization(Organization $organization, Feature $feature, ?Jurisdiction $jurisdiction, array $switches): ?Decision
    {
        if ($feature->startsFiling()) {
            try {
                // Suspended: nothing new is issued, and what was issued is
                // still filed.
                if ($feature->issues()) {
                    $this->clearance->assertNotSuspended($organization);
                }

                $this->clearance->assertMayFile($organization);
            } catch (ComplianceException $e) {
                return Decision::refuse($feature, Level::Organization, 'status',
                    $e->getErrorCode() === ErrorCode::AUTH_ORGANIZATION_SUSPENDED ? __('This company is suspended. Contact support.') : $e->getMessage(),
                    ['status' => $organization->status]);
            }
        }

        foreach ($this->killSwitches($feature, $jurisdiction) as $switch) {
            if (! $this->killSwitch->isEnabled($switch, (string) $organization->id)) {
                continue;
            }

            $info = $this->killSwitch->getSwitchInfo($switch, (string) $organization->id) ?? [];

            return Decision::refuse($feature, Level::Organization, 'org_kill_switch',
                __('Paused for this company by an operator: :reason', ['reason' => $info['reason'] ?? __('no reason given.')]),
                ['kill_switch' => $switch, 'reason' => $info['reason'] ?? null, 'expires_at' => $info['expires_at'] ?? null]);
        }

        $switch = $switches[$feature->value] ?? null;

        if ($switch !== null && $switch['state'] === FeatureOverride::OFF && $feature->canSwitchOff()) {
            return Decision::refuse($feature, Level::Organization, 'override_off',
                __('Switched off for this company by a platform admin: :reason', ['reason' => $switch['reason']]),
                ['override' => $switch]);
        }

        return null;
    }

    private function role(Organization $organization, ?User $user, Feature $feature): ?Decision
    {
        $needs = $feature->minimumRole();

        if ($user === null || $needs === null) {
            return null;
        }

        $role = $user->roleIn((string) $organization->id);

        if ($role === null) {
            return Decision::refuse($feature, Level::Role, 'not_member',
                __('You are not a member of this organization.'), ['needs' => $needs]);
        }

        if (MemberRole::rank($role) >= MemberRole::rank($needs)) {
            return null;
        }

        return Decision::refuse($feature, Level::Role, 'role',
            $needs === 'admin' ? __('Only an administrator of this organization can do this.') : __('Your role in this organization is read-only.'),
            ['role' => $role, 'needs' => $needs]);
    }

    /**
     * A platform admin's switch for the person's role in the organization,
     * then for the person: off only - nothing here grants what a broader
     * level refused.
     */
    private function people(Organization $organization, ?User $user, Feature $feature): ?Decision
    {
        if ($user === null || ! $feature->canSwitchForPeople()) {
            return null;
        }

        $people = $this->overrides->forPeople((string) $organization->id);
        $role = (string) $user->roleIn((string) $organization->id);
        $forRole = $people[FeatureOverrides::role($role)][$feature->value] ?? null;

        if (($forRole['state'] ?? null) === FeatureOverride::OFF) {
            return Decision::refuse($feature, Level::Role, 'role_override_off',
                __('Switched off for this role in the company by a platform admin: :reason', ['reason' => $forRole['reason']]),
                ['role' => $role, 'override' => $forRole]);
        }

        $forPerson = $people[FeatureOverrides::person((string) $user->id)][$feature->value] ?? null;

        if (($forPerson['state'] ?? null) === FeatureOverride::OFF) {
            return Decision::refuse($feature, Level::User, 'user_override_off',
                __('Switched off for you in this company by a platform admin: :reason', ['reason' => $forPerson['reason']]),
                ['override' => $forPerson]);
        }

        return null;
    }

    /**
     * @param  array<string, array{state: string, reason: string, by: string|null, at: string|null}>  $switches
     */
    private function allowed(Feature $feature, array $switches): Decision
    {
        $switch = $switches[$feature->value] ?? null;

        if ($switch !== null && $switch['state'] === FeatureOverride::ON && $feature->canGrantBeyondPlan()) {
            return new Decision($feature, true,
                __('Available: granted beyond the plan by a platform admin: :reason', ['reason' => $switch['reason']]),
                Level::Plan, 'override_on', ['override' => $switch]);
        }

        return Decision::allow($feature);
    }

    private function grantedBeyondPlan(?string $organizationId, Feature $feature): bool
    {
        return $organizationId !== null
            && $feature->canGrantBeyondPlan()
            && $this->overrides->state($organizationId, $feature) === FeatureOverride::ON;
    }

    /**
     * The kill switches that stop the feature: every country's, and the
     * country's own.
     *
     * @return list<string>
     */
    private function killSwitches(Feature $feature, ?Jurisdiction $jurisdiction): array
    {
        return array_values(array_unique([
            ...$feature->killSwitches(),
            ...($jurisdiction?->features()->killSwitchesFor($feature) ?? []),
        ]));
    }

    /**
     * The organization's module, switched on or not: a feature its country
     * does not have is refused as such even while the country is off. Null
     * when no module answers for the country.
     */
    private function jurisdiction(Organization $organization): ?Jurisdiction
    {
        try {
            return $this->registry->get(blank($organization->country) ? $this->registry->defaultCode() : (string) $organization->country);
        } catch (JurisdictionUnavailableException) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use App\Domains\Platform\Services\OrganizationReport;
use DomainException;

/**
 * A platform admin's switch for one feature of one organization: off
 * whatever its plan says, on beyond its plan, or back to the default.
 */
final class FeatureActions implements ApprovableActions
{
    public const DEFAULT = 'default';

    public function __construct(
        private readonly FeatureOverrides $overrides,
        private readonly OrganizationReport $organizations,
    ) {}

    public function keys(): array
    {
        return ['features.override'];
    }

    public function label(string $action): string
    {
        return __('Switch a feature off or on for one organization');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::FeaturesManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $organization = $this->organizations->find((string) $payload['organization_id'])
            ?? throw new DomainException(__('There is no such organization.'));
        $feature = Feature::from((string) $payload['feature']);

        return new Preview(
            $feature->label().' → '.$payload['state'].' for '.$organization->name.': '.$payload['reason'],
            'Organization', (string) $payload['organization_id'],
            ['feature' => $feature->value, 'state' => $this->overrides->state((string) $payload['organization_id'], $feature) ?? self::DEFAULT],
            ['feature' => $feature->value, 'state' => $payload['state'], 'reason' => $payload['reason']],
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $feature = Feature::from((string) $payload['feature']);
        $state = (string) $payload['state'];

        // The email, not the id: read by someone asking who switched it, as
        // the countries and kill switches record it.
        $this->overrides->set((string) $payload['organization_id'], $feature, $state === self::DEFAULT ? null : $state, (string) $payload['reason'], $requester->email);

        return new ActionResult(match ($state) {
            'off' => $feature->label().' is switched off for this organization.',
            'on' => $feature->label().' is granted beyond the plan for this organization.',
            default => $feature->label().' follows the defaults again.',
        });
    }
}

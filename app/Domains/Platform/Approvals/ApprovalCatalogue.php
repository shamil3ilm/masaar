<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

use App\Domains\Platform\Approvals\Actions\AccountActions;
use App\Domains\Platform\Approvals\Actions\AdminAccessActions;
use App\Domains\Platform\Approvals\Actions\AdminRoleActions;
use App\Domains\Platform\Approvals\Actions\BillingActions;
use App\Domains\Platform\Approvals\Actions\CountryActions;
use App\Domains\Platform\Approvals\Actions\FeatureActions;
use App\Domains\Platform\Approvals\Actions\KillSwitchActions;
use App\Domains\Platform\Approvals\Actions\LicenceActions;
use App\Domains\Platform\Approvals\Actions\OrganizationActions;
use App\Domains\Platform\Approvals\Actions\PolicyActions;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Every admin action an approval policy can hold back, by key, and the
 * family that does it.
 *
 * RESERVED are actions the platform will have, named now so a policy can be
 * set for them before they exist: freezing an account for non-payment comes
 * with payments. Asking for one is a programming error until a family takes
 * it.
 */
final class ApprovalCatalogue
{
    /** @var array<string, class-string<ApprovableActions>> */
    public const ACTIONS = [
        'organizations.approve' => OrganizationActions::class,
        'organizations.reject' => OrganizationActions::class,
        'organizations.suspend' => OrganizationActions::class,
        'organizations.reactivate' => OrganizationActions::class,
        'billing.record' => BillingActions::class,
        'countries.switch' => CountryActions::class,
        'kill_switches.enable' => KillSwitchActions::class,
        'kill_switches.disable' => KillSwitchActions::class,
        'kill_switches.stop' => KillSwitchActions::class,
        'kill_switches.resume' => KillSwitchActions::class,
        'features.override' => FeatureActions::class,
        'admins.grant' => AdminAccessActions::class,
        'admins.invite' => AdminAccessActions::class,
        'admins.role' => AdminAccessActions::class,
        'admins.revoke' => AdminAccessActions::class,
        'admin_roles.save' => AdminRoleActions::class,
        'admin_roles.delete' => AdminRoleActions::class,
        'users.suspend' => AccountActions::class,
        'users.reactivate' => AccountActions::class,
        'licences.issue' => LicenceActions::class,
        'licences.revoke' => LicenceActions::class,
        'licences.allowances' => LicenceActions::class,
        'approvals.policies' => PolicyActions::class,
    ];

    /** Actions to come, which a policy can already be set for. */
    public const RESERVED = ['accounts.freeze', 'accounts.unfreeze'];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Every action a policy can be set for, in the order the policies page
     * lists them.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return [...array_keys(self::ACTIONS), ...self::RESERVED];
    }

    public function has(string $action): bool
    {
        return in_array($action, $this->keys(), true);
    }

    /**
     * @throws InvalidArgumentException for an unknown or reserved action
     */
    public function family(string $action): ApprovableActions
    {
        $class = self::ACTIONS[$action] ?? throw new InvalidArgumentException("No admin action can be run as {$action}.");

        return $this->container->make($class);
    }

    public function label(string $action): string
    {
        return match ($action) {
            'accounts.freeze' => __('Freeze an account'),
            'accounts.unfreeze' => __('Unfreeze an account'),
            ApprovalPolicies::BYPASS => __('Allow bypass when no other approver exists'),
            default => $this->family($action)->label($action),
        };
    }
}

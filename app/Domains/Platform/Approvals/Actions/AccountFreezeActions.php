<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Services\Freeze;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * A customer's account frozen by hand for non-payment, or the freeze lifted
 * until a date: the hand half of the overdue-payment freeze, with the
 * reason it was done.
 */
final class AccountFreezeActions implements ApprovableActions
{
    public const FREEZE = 'accounts.freeze';

    public const UNFREEZE = 'accounts.unfreeze';

    public function __construct(
        private readonly Freeze $freeze,
    ) {}

    public function keys(): array
    {
        return [self::FREEZE, self::UNFREEZE];
    }

    public function label(string $action): string
    {
        return $action === self::FREEZE ? __('Freeze an account') : __('Unfreeze an account');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::BillingManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $organization = $this->organization($payload);
        $standing = $this->freeze->of($organization);

        return $action === self::FREEZE
            ? new Preview(__('Freeze :customer: :reason', ['customer' => $organization->name, 'reason' => $payload['reason']]),
                'Organization', (string) $organization->id, ['standing' => $standing->stage], ['standing' => 'frozen', 'reason' => $payload['reason']])
            : new Preview(__('Unfreeze :customer until :date: :reason', ['customer' => $organization->name, 'date' => $payload['until'], 'reason' => $payload['reason']]),
                'Organization', (string) $organization->id, ['standing' => $standing->stage], ['standing' => 'good', 'until' => $payload['until'], 'reason' => $payload['reason']]);
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $organization = $this->organization($payload);

        if ($action === self::FREEZE) {
            $this->freeze->freeze($organization, (string) $payload['reason'], (string) $requester->id);

            return new ActionResult(__('Frozen. It issues nothing new and adds nobody; it still reads and exports everything, and what it issued keeps being filed.'));
        }

        $this->freeze->unfreeze($organization, (string) $payload['reason'], CarbonImmutable::parse((string) $payload['until']), (string) $requester->id);

        return new ActionResult(__('Unfrozen until :date.', ['date' => $payload['until']]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function organization(array $payload): Organization
    {
        return Organization::query()->find((string) $payload['organization_id']) ?? throw new DomainException(__('There is no such organization.'));
    }
}

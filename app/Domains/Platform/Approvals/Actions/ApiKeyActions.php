<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Services\ApiKeys;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use App\Domains\Platform\Approvals\RequestedByCustomers;
use DomainException;

/**
 * A production API key an organization's administrator made in the portal,
 * put to use. Asked for by the customer, approved by a platform admin, when
 * the policy is on; the key exists from the start and only starts working
 * once this runs.
 */
final class ApiKeyActions implements ApprovableActions, RequestedByCustomers
{
    public function __construct(
        private readonly ApiKeys $keys,
    ) {}

    public function keys(): array
    {
        return [ApiKeys::APPROVAL];
    }

    public function label(string $action): string
    {
        return __('Activate a production API key a customer made');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::LicencesManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $key = $this->key($payload);

        return new Preview(
            'Activate the production API key "'.$key->name.'" ('.$key->api_key.')',
            'ApiKey',
            (string) $key->id,
            ['status' => $key->status],
            ['status' => 'active', 'scopes' => $key->scopes, 'allowed_ips' => $key->allowed_ips, 'expires_at' => $key->expires_at?->toIso8601String()],
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $this->keys->activate((string) $payload['api_key_id']);

        return new ActionResult('API key activated.', ['api_key_id' => (string) $payload['api_key_id']]);
    }

    public function requesterMayStill(string $action, array $payload, User $requester): bool
    {
        $key = $this->keys->byId((string) ($payload['api_key_id'] ?? ''));

        return $key !== null && MemberRole::administers($requester->roleIn((string) $key->org_id));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function key(array $payload): ApiKey
    {
        return $this->keys->byId((string) ($payload['api_key_id'] ?? ''))
            ?? throw new DomainException(__('That API key has been revoked.'));
    }
}

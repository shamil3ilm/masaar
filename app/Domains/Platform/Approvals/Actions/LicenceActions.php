<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Services\LicenseManagementService;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;

/**
 * Licences: issuing one, revoking one, and changing a customer's allowances.
 */
final class LicenceActions implements ApprovableActions
{
    /** What issuing takes from the form, and nothing else. */
    private const ISSUED = ['org_id', 'organization_name', 'contact_email', 'organization_vat', 'contact_phone', 'tier', 'environment', 'expires_at', 'notes'];

    public function __construct(
        private readonly LicenseManagementService $licences,
    ) {}

    public function keys(): array
    {
        return ['licences.issue', 'licences.revoke', 'licences.allowances'];
    }

    public function label(string $action): string
    {
        return match ($action) {
            'licences.issue' => __('Issue a licence'),
            'licences.revoke' => __('Revoke a licence'),
            default => __('Change a customer\'s allowances'),
        };
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::LicencesManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        if ($action === 'licences.issue') {
            return new Preview('Issue a '.$payload['tier'].' licence to '.$payload['organization_name'], 'Organization', $payload['org_id'] ?? null,
                [], array_intersect_key($payload, array_flip(self::ISSUED)));
        }

        $licence = $this->licences->getLicenseDetails((string) $payload['licence_id'])['license'];
        $name = (string) ($licence['organization_name'] ?? $payload['licence_id']);

        return $action === 'licences.revoke'
            ? new Preview('Revoke the licence of '.$name.': '.$payload['reason'], 'License', (string) $payload['licence_id'],
                ['status' => $licence['status'] ?? null], ['status' => LicenseStatus::Revoked->value, 'reason' => $payload['reason']])
            : new Preview('Change the allowances of '.$name, 'License', (string) $payload['licence_id'],
                ['invoices_per_month' => $licence['invoices_per_month'] ?? null, 'calls_per_day' => $licence['calls_per_day'] ?? null],
                ['invoices_per_month' => $payload['invoices_per_month'], 'calls_per_day' => $payload['calls_per_day']]);
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        if ($action === 'licences.issue') {
            // Issued from the console means usable: the service's default is
            // pending_activation, which no screen can turn on.
            $issued = $this->licences->createLicense([
                ...array_fill_keys(self::ISSUED, null),
                ...array_intersect_key($payload, array_flip(self::ISSUED)),
                'status' => LicenseStatus::Active->value,
            ]);

            return new ActionResult('Licence issued.', ['licence_id' => (string) $issued['license']->id, 'api_secret' => $issued['api_secret']]);
        }

        if ($action === 'licences.revoke') {
            $this->licences->revokeLicense((string) $payload['licence_id'], (string) $payload['reason']);

            return new ActionResult('Licence revoked.', ['licence_id' => (string) $payload['licence_id']]);
        }

        $this->licences->updateLimits((string) $payload['licence_id'], [
            'invoices_per_month' => (int) $payload['invoices_per_month'],
            'calls_per_day' => (int) $payload['calls_per_day'],
        ]);

        return new ActionResult('Allowances saved.', ['licence_id' => (string) $payload['licence_id']]);
    }
}

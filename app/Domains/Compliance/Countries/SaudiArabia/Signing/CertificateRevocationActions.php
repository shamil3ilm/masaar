<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use DomainException;

/**
 * Marking a device certificate revoked, as an admin action an approval
 * policy holds. Held as shipped, like the changes to admin access: it stops
 * a till signing until the device is onboarded again.
 *
 * Added to the catalogue by the module's service provider.
 */
final class CertificateRevocationActions implements ApprovableActions
{
    public const REVOKE = 'certificates.revoke';

    public function __construct(
        private readonly CertificateRevocations $revocations,
    ) {}

    public function keys(): array
    {
        return [self::REVOKE];
    }

    public function label(string $action): string
    {
        return __('Mark a device certificate revoked');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::PlatformConfigure;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $version = $this->version($payload);

        $this->assertNotRevoked($version);

        return new Preview(
            'Mark certificate version '.$version->version.' of '.($version->org?->name ?? $version->org_id).' revoked',
            'CertificateVersion', (string) $version->getKey(),
            ['status' => $version->status],
            ['status' => CertificateVersion::REVOKED, 'reason' => (string) $payload['reason']],
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $version = $this->version($payload);

        // Who asked for it, whoever approved it.
        $this->revocations->markRevoked($version, (string) $payload['reason'], (string) $requester->id);

        return new ActionResult("Version {$version->version} is recorded as revoked. Nothing more is filed with it.");
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws DomainException when the version is gone
     */
    private function version(array $payload): CertificateVersion
    {
        return CertificateVersion::withoutTenantScope(fn () => CertificateVersion::query()->with('org')->find((int) $payload['version_id']))
            ?? throw new DomainException('That certificate version no longer exists.');
    }

    /**
     * @throws DomainException when it is already revoked
     */
    private function assertNotRevoked(CertificateVersion $version): void
    {
        if ($version->status === CertificateVersion::REVOKED) {
            throw new DomainException(__('This certificate is already recorded as revoked.'));
        }
    }
}

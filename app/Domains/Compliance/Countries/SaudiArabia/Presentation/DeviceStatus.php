<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\Contracts\Devices;
use App\Domains\Compliance\Engine\DTOs\Panel;
use App\Domains\Organization\Services\BranchService;

/**
 * A Saudi branch as a ZATCA EGS unit: its onboarding, and the certificate it
 * signs with.
 *
 * Each EGS unit is onboarded with ZATCA on its own (ZATCA E-Invoicing
 * Detailed Technical Guidelines, onboarding: a CSID per EGS unit), and a
 * branch is one: the Submitter refuses an invoice from a branch whose
 * onboarding is not active, so the branch screens say so before a cashier
 * writes one. Onboarding itself runs through the branch onboarding API
 * (BranchOnboardingController).
 */
class DeviceStatus implements Devices
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly CredentialStore $credentials,
    ) {}

    public function branchStatus(string $organizationId, string $branchId): ?Panel
    {
        $branch = $this->branches->find($organizationId, $branchId);

        if ($branch === null) {
            return null;
        }

        return new Panel('countries.saudi-arabia.branch-device', [
            'status' => (string) $branch->onboarding_status,
            'ready' => $branch->isFatooraReady(),
            'serial' => (string) $branch->device_serial,
            'compliance' => $this->credentials->has($organizationId, $branchId, CredentialStore::CCSID),
            'production' => $this->credentials->has($organizationId, $branchId, CredentialStore::PCSID),
            'expires' => $branch->cert_expires_at,
            'days' => $branch->getDaysUntilCertificateExpiry(),
        ]);
    }

    public function refusal(string $organizationId, string $branchId): ?string
    {
        $branch = $this->branches->find($organizationId, $branchId);

        if ($branch === null || $branch->isFatooraReady()) {
            return null;
        }

        return match (true) {
            in_array($branch->onboarding_status, [$branch::STATUS_SUSPENDED, $branch::STATUS_REVOKED], true) => __('This branch\'s ZATCA device is suspended, so ZATCA would refuse an invoice from it. Reactivate or onboard it again, or choose All branches to issue from head office.'),
            $branch->isCertificateExpired() => __('This branch\'s ZATCA certificate has expired, so ZATCA would refuse an invoice from it. Renew it, or choose All branches to issue from head office.'),
            default => __('This branch is not yet onboarded with ZATCA as its own device, so ZATCA would refuse an invoice from it. Onboard the branch, or choose All branches to issue from head office.'),
        };
    }
}

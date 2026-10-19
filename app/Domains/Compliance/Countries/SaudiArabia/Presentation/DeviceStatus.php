<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\Contracts\Devices;
use App\Domains\Compliance\Engine\DTOs\Panel;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Organization\Services\Tills;

/**
 * A Saudi branch's tills as ZATCA EGS units: each one's onboarding, the
 * certificate it signs with, and whether it still issues.
 *
 * Each EGS unit is onboarded with ZATCA on its own (E-Invoicing Detailed
 * Guidelines p10 and p39: a CSID per unit, every unit registered), and a
 * branch has one or more: its first under the branch's own id, any others
 * added since. The Submitter refuses an invoice from a till whose
 * onboarding is not active, so the branch screens say so before a cashier
 * writes one.
 */
class DeviceStatus implements Devices
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly Tills $tills,
        private readonly CredentialStore $credentials,
        private readonly PrintPreferences $printing,
    ) {}

    public function branchStatus(string $organizationId, string $branchId): ?Panel
    {
        $branch = $this->branches->find($organizationId, $branchId);

        if ($branch === null) {
            return null;
        }

        return new Panel('countries.saudi-arabia.branch-device', [
            'branch' => (string) $branch->id,
            'papers' => PaperSize::cases(),
            'tills' => $this->tills->forBranch($organizationId, $branchId)
                ->map(fn ($unit): array => [
                    'id' => (string) $unit->id,
                    'name' => $unit->label(),
                    'number' => $unit->till_number,
                    'status' => $unit->status,
                    'status_label' => $unit->statusLabel(),
                    'onboarding' => $unit->onboarding_status,
                    'ready' => $unit->isReady() && (bool) $branch->is_active,
                    'serial' => (string) $unit->egs_serial,
                    'compliance' => $this->credentials->has($organizationId, (string) $unit->id, CredentialStore::CCSID),
                    'production' => $this->credentials->has($organizationId, (string) $unit->id, CredentialStore::PCSID),
                    'expires' => $unit->cert_expires_at,
                    'days' => $unit->daysUntilCertificateExpiry(),
                    'files' => $unit->files(),
                    // Its own paper and receipt printer, over the branch's.
                    'printing' => $this->printing->tillPrinting($organizationId, (string) $unit->id),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Why the branch's default till - the one the portal issues on - would
     * be refused now.
     */
    public function refusal(string $organizationId, string $branchId): ?string
    {
        $branch = $this->branches->find($organizationId, $branchId);

        if ($branch === null) {
            return null;
        }

        $unit = $this->tills->defaultFor($organizationId, $branchId);

        if ($unit->isHeadOffice()) {
            return __('This branch has no till that still issues. Add one, or choose All branches to issue from head office.');
        }

        if ($unit->isReady() && $branch->is_active) {
            return null;
        }

        return match (true) {
            ! $branch->is_active || in_array($unit->onboarding_status, [$branch::STATUS_SUSPENDED, $branch::STATUS_REVOKED], true) => __('This branch\'s ZATCA device is suspended, so ZATCA would refuse an invoice from it. Reactivate or onboard it again, or choose All branches to issue from head office.'),
            $unit->isCertificateExpired() => __('This branch\'s ZATCA certificate has expired, so ZATCA would refuse an invoice from it. Renew it, or choose All branches to issue from head office.'),
            default => __('This branch is not yet onboarded with ZATCA as its own device, so ZATCA would refuse an invoice from it. Onboard the branch, or choose All branches to issue from head office.'),
        };
    }
}

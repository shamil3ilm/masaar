<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Webhook\Services\WebhookService;

/**
 * The webhooks a ZATCA onboarding step announces when it succeeds: the
 * compliance certificate obtained, the compliance documents passed, the
 * production certificate obtained - for the organization's own device, or
 * one of its tills, which unit_id names.
 *
 * Announced to the organization whose step it was, and only to it: the
 * endpoints are looked up by that organization's id (WebhookService::
 * dispatch), never by anything the request names.
 */
class OnboardingEvents
{
    public const CCSID_OBTAINED = 'onboarding.ccsid_obtained';

    public const COMPLIANCE_PASSED = 'onboarding.compliance_passed';

    public const PCSID_OBTAINED = 'onboarding.pcsid_obtained';

    public function __construct(
        private readonly WebhookService $webhooks,
    ) {}

    /**
     * @param  string|null  $branchId  the branch onboarded; null for the organization's own device
     * @param  string|null  $unitId  the till onboarded: a branch's first till has the branch's id
     */
    public function announce(string $organizationId, string $event, ?string $branchId = null, ?string $unitId = null): void
    {
        $this->webhooks->dispatch($organizationId, $event, [
            'org_id' => $organizationId,
            'branch_id' => $branchId,
            'unit_id' => $unitId ?? $branchId ?? $organizationId,
            'occurred_at' => now()->toIso8601String(),
        ]);
    }
}

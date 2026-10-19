<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Approvals\Outcome;

/**
 * A manual revocation put to the platform's approvals: done now when its
 * policy is off (or nobody else could approve it, while bypass is allowed),
 * otherwise a request that CertificateRevocationActions runs once approved.
 */
final class GovernedRevocationApprovals implements RevocationApprovals
{
    public function __construct(
        private readonly Approvals $approvals,
    ) {}

    public function submit(CertificateVersion $version, string $reason, User $requester): Outcome
    {
        return $this->approvals->submit(
            CertificateRevocationActions::REVOKE,
            ['version_id' => (int) $version->getKey(), 'reason' => $reason],
            $requester,
            $reason,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;

/**
 * Every manual revocation takes effect at once - the behaviour until an
 * approval policy is bound in its place (see RevocationApprovals).
 */
final class ImmediateRevocationApprovals implements RevocationApprovals
{
    public function approve(CertificateVersion $version, string $reason, ?string $requestedBy): bool
    {
        return true;
    }
}

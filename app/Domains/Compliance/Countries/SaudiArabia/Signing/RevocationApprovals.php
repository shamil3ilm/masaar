<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;

/**
 * The approval-policy hook for marking a certificate revoked by hand.
 *
 * Marking a certificate revoked stops the device signing at once, so it is
 * the kind of action an approval policy would hold for a second person. The
 * platform has no approval policies yet; ImmediateRevocationApprovals is
 * bound until it does, and approves every request. An approval policy binds
 * its own implementation in the module's service provider.
 *
 * The revocation list itself is never held for approval: a certificate the
 * CA has published as revoked is revoked whatever anyone here decides.
 */
interface RevocationApprovals
{
    /**
     * Whether the revocation may take effect now. False means it was put to
     * the policy and waits there.
     */
    public function approve(CertificateVersion $version, string $reason, ?string $requestedBy): bool;
}

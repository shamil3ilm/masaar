<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Platform\Approvals\Outcome;
use DomainException;

/**
 * The approval-policy hook for marking a certificate revoked by hand.
 *
 * Marking a certificate revoked stops the device signing at once, so it is
 * the kind of action an approval policy holds for a second person.
 * GovernedRevocationApprovals is bound: the platform's approvals decide,
 * held as shipped (CertificateRevocationActions).
 *
 * The revocation list itself is never held for approval: a certificate the
 * CA has published as revoked is revoked whatever anyone here decides.
 */
interface RevocationApprovals
{
    /**
     * Ask for the revocation: done now, or a request waiting for approval
     * that runs it once approved.
     *
     * @throws DomainException when the version is already revoked
     */
    public function submit(CertificateVersion $version, string $reason, User $requester): Outcome;
}

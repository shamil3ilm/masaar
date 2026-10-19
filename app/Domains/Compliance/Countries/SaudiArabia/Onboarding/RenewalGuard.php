<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\UnfiledDocuments;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateIdentity;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Support\Collection;

/**
 * No renewal while documents signed by the current certificate are unfiled.
 *
 * Renewal revokes the existing certificate: "The CA validates that the
 * existing certificate is not revoked or renewed before then revoke existing
 * certificate" (Security Features Implementation Standard v1.2, section
 * 2.1.2, step 4). A document stamped with it and not yet reported may then be
 * refused, and ZATCA's documents do not say how such a document is to be
 * filed. So the renewal waits until they are: queued offline, pending or
 * retrying, or overdue.
 *
 * An emergency - a compromised key - cannot wait. An administrator can renew
 * anyway by giving a reason, which is audited with the documents left behind.
 */
class RenewalGuard
{
    /** The shortest reason an override is accepted with. */
    public const MIN_REASON = 10;

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly UnfiledDocuments $unfiled,
        private readonly AuditService $audit,
    ) {}

    /**
     * What the device's current certificate signed that ZATCA does not hold
     * yet: what a renewal now would put at risk. Empty when the device holds
     * no production certificate, which is not a renewal.
     *
     * @return Collection<int, array{id: string, invoice_number: string, reason: string}>
     */
    public function waiting(string $organizationId, ?string $branchId): Collection
    {
        $held = $this->credentials->get($organizationId, $branchId, CredentialStore::PCSID);

        if (empty($held[CredentialStore::PCSID])) {
            return collect();
        }

        return $this->unfiled->signedBy(
            $organizationId,
            $branchId ?? $organizationId,
            CertificateIdentity::of((string) $held[CredentialStore::PCSID])->id,
        );
    }

    /**
     * @throws DomainException listing what is unfiled, when renewal must wait
     */
    public function assertMayRenew(string $organizationId, ?string $branchId, ?string $overrideReason): void
    {
        $waiting = $this->waiting($organizationId, $branchId);

        if ($waiting->isEmpty()) {
            return;
        }

        $reason = trim((string) $overrideReason);

        if (mb_strlen($reason) >= self::MIN_REASON) {
            // Against the organization, so it is on the organization's own record.
            $this->audit->log('zatca.renewal.override', Organization::query()->find($organizationId), null, null, [
                'org_id' => $organizationId,
                'branch_id' => $branchId,
                'reason' => $reason,
                'unfiled' => $waiting->pluck('invoice_number')->all(),
            ]);

            return;
        }

        $listed = $waiting->take(UnfiledDocuments::LISTED)->pluck('invoice_number')->implode(', ');
        $more = $waiting->count() - UnfiledDocuments::LISTED;

        throw new DomainException(trans_choice(
            'One document signed with the current certificate has not been filed with ZATCA yet: :list. Renewing revokes that certificate, so file it first, or give a reason to renew anyway.|:count documents signed with the current certificate have not been filed with ZATCA yet: :list. Renewing revokes that certificate, so file them first, or give a reason to renew anyway.',
            $waiting->count(),
            ['count' => $waiting->count(), 'list' => $more > 0 ? __(':list and :more more', ['list' => $listed, 'more' => $more]) : $listed],
        ));
    }
}

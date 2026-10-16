<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuingLifecycle;
use App\Domains\Organization\Models\Organization;
use DomainException;

/**
 * Issues a UAE draft and sends it as a Peppol document.
 *
 * The issuing lifecycle is the one Saudi invoices go through
 * (IssuingLifecycle); the UAE's own steps are UaeJurisdiction. Asked as a
 * reissue, so a second call on an issued invoice issues nothing and queues
 * nothing: it answers with the document the first one stored.
 */
class UaeInvoiceIssuer
{
    public function __construct(
        private readonly IssuingLifecycle $lifecycle,
        private readonly UaeJurisdiction $uae,
    ) {}

    /**
     * @throws IssuanceRefusedException listing every problem; the invoice is still a draft
     */
    public function issue(Invoice $invoice): PeppolDocument
    {
        $organization = Organization::query()->findOrFail($invoice->org_id);

        try {
            return $this->lifecycle->issue($invoice, $organization, $this->uae, reissue: true);
        } catch (DomainException $e) {
            // The number and moment were set on this model and rolled back in
            // the database; the caller must not read them.
            rescue(fn () => $invoice->refresh(), report: false);

            throw $e instanceof IssuanceRefusedException ? $e : new IssuanceRefusedException([$e->getMessage()]);
        }
    }
}

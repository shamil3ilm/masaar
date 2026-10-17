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
 * reissue, so a second call on an issued invoice issues nothing: it answers
 * with the document the first one stored.
 *
 * Sending is queueing that document, once (PeppolSender::queueOnce). A
 * caller may issue without sending - the pipeline's auto_submit false - and
 * a later call that sends queues the document then; one already queued, or
 * past ready, is never queued again.
 */
class UaeInvoiceIssuer
{
    public function __construct(
        private readonly IssuingLifecycle $lifecycle,
        private readonly UaeJurisdiction $uae,
        private readonly PeppolSender $sender,
    ) {}

    /**
     * @param  bool  $send  queue the document for the service provider
     *
     * @throws IssuanceRefusedException listing every problem; the invoice is still a draft
     */
    public function issue(Invoice $invoice, bool $send = true): PeppolDocument
    {
        $organization = Organization::query()->findOrFail($invoice->org_id);

        try {
            $document = $this->lifecycle->issue($invoice, $organization, $this->uae, reissue: true);
        } catch (DomainException $e) {
            // The number and moment were set on this model and rolled back in
            // the database; the caller must not read them.
            rescue(fn () => $invoice->refresh(), report: false);

            throw $e instanceof IssuanceRefusedException ? $e : new IssuanceRefusedException([$e->getMessage()]);
        }

        if ($send) {
            $this->sender->queueOnce($document);
        }

        return $document;
    }
}

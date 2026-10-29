<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing;

use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuingLifecycle;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Throwable;

/**
 * Issues a UAE draft and sends it as a Peppol document.
 *
 * The issuing lifecycle is the shared one every country goes through
 * (IssuingLifecycle); the UAE's own steps are IssuingStep. Asked as a
 * reissue, so a second call on an issued invoice issues nothing: it answers
 * with the document the first one stored.
 *
 * Sending is queueing that document, once (PeppolSender::queueOnce). A
 * caller may issue without sending - the pipeline's auto_submit false - and
 * a later call that sends queues the document then; one already queued, or
 * past ready, is never queued again.
 */
class InvoiceIssuer
{
    public function __construct(
        private readonly IssuingLifecycle $lifecycle,
        private readonly IssuingStep $uae,
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
            $this->discardRolledBackValues($invoice);

            throw $e instanceof IssuanceRefusedException ? $e : new IssuanceRefusedException([$e->getMessage()]);
        } catch (Throwable $e) {
            // Anything else - the device busy (SYS_BUSY) among them - is
            // undone the same way and passed on as it is: a busy device is a
            // retry, which a refusal is not.
            $this->discardRolledBackValues($invoice);

            throw $e;
        }

        if ($send) {
            $this->sender->queueOnce($document);
        }

        return $document;
    }

    /**
     * The number, the moment and the status were set on this model and
     * rolled back in the database; the caller must not read them.
     */
    private function discardRolledBackValues(Invoice $invoice): void
    {
        rescue(fn () => $invoice->refresh(), report: false);
    }
}

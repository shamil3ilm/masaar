<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Fatoora\Services\IssuanceNotifier;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceNumbering;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Issues a UAE draft and sends it as a Peppol document.
 *
 * Two parts, kept apart so the first can move into an issuer shared with
 * Saudi Arabia once that one is extracted from ZATCA's signing:
 *
 *   The issuing lifecycle, as every country has it: under the organization
 *   row's lock the draft takes the next number in its sequence
 *   (InvoiceNumbering) and its moment of issue, and becomes issued; then
 *   invoice.issued is raised (IssuanceNotifier).
 *
 *   The UAE jurisdiction step: problems() - the mandatory fields, and the
 *   stored totals against the document's - before anything is written;
 *   then PeppolPreparer stores the document in the same transaction, and
 *   PeppolSender queues it for the service provider.
 *
 * Nothing of ZATCA's runs: no counter, hash, signature, QR or chain.
 *
 * A refused invoice stays a draft and keeps no number. A second call on an
 * issued invoice issues nothing and queues nothing: it answers with the
 * document the first one stored.
 */
class UaeInvoiceIssuer
{
    public function __construct(
        private readonly InvoiceNumbering $numbering,
        private readonly PeppolPreparer $preparer,
        private readonly PeppolSender $sender,
        private readonly IssuanceNotifier $notifier,
    ) {}

    /**
     * @throws IssuanceRefusedException listing every problem; the invoice is still a draft
     */
    public function issue(Invoice $invoice): PeppolDocument
    {
        try {
            [$issuedNow, $document] = DB::transaction(function () use ($invoice): array {
                Organization::query()->whereKey($invoice->org_id)->lockForUpdate()->first();

                // Decided on the row as it stands under the lock: two requests
                // issuing one draft both saw a draft before it.
                $invoice->refresh();
                $issuedNow = $invoice->status === InvoiceStatus::Draft;

                if ($issuedNow) {
                    if (blank($invoice->invoice_number)) {
                        $invoice->invoice_number = $this->numbering->allocate($invoice);
                    }

                    $invoice->issued_at ??= now();
                    $this->refuseOnProblems($invoice);
                    $invoice->status = InvoiceStatus::Issued;
                    $invoice->save();
                }

                return [$issuedNow, $this->preparer->prepare($invoice)];
            });
        } catch (DomainException $e) {
            // The number and moment were set on this model and rolled back in
            // the database; the caller must not read them.
            rescue(fn () => $invoice->refresh(), report: false);

            throw $e instanceof IssuanceRefusedException ? $e : new IssuanceRefusedException([$e->getMessage()]);
        }

        if ($issuedNow) {
            $this->notifier->issued($invoice);
        }

        if ($document->wasRecentlyCreated) {
            $this->sender->queue($document);
        }

        return $document;
    }

    /**
     * What would stop the invoice being sent: the mandatory fields, then
     * whether the document would say what the invoice says.
     *
     * @return list<string>
     */
    public function problems(Invoice $invoice): array
    {
        $problems = $this->preparer->problems($invoice);

        return $problems !== [] ? $problems : $this->totalsDisagree($invoice);
    }

    /** @throws IssuanceRefusedException */
    private function refuseOnProblems(Invoice $invoice): void
    {
        $problems = $this->problems($invoice);

        if ($problems !== []) {
            throw new IssuanceRefusedException($problems);
        }
    }

    /**
     * The document's totals are computed from the lines; the invoice's were
     * stored when it was drafted, and the PDF and the API show those. A
     * difference would be two amounts for one invoice, so it is refused.
     *
     * @return list<string>
     */
    private function totalsDisagree(Invoice $invoice): array
    {
        $document = $this->preparer->document($invoice);
        $problems = [];

        foreach ([
            __('Total') => [(string) ($invoice->total ?? '0'), $document->payableAmount],
            __('VAT') => [(string) ($invoice->tax_amount ?? '0'), $document->taxAmount],
        ] as $label => [$stored, $computed]) {
            if (bccomp($stored, $computed, 2) !== 0) {
                $problems[] = __(':label: the invoice says :stored and its e-invoice :computed.', [
                    'label' => $label, 'stored' => $stored, 'computed' => $computed,
                ]);
            }
        }

        return $problems;
    }
}

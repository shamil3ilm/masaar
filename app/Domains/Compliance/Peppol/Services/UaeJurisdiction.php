<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\KillSwitch;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Contracts\Seal;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuanceNotifier;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use Throwable;

/**
 * The UAE's part of issuing an invoice: a Peppol document, not a ZATCA one.
 *
 * IssuingLifecycle numbers the invoice, sets its moment of issue and marks it
 * issued under the organization's lock, as it does for Saudi Arabia. This
 * adds the guards every issuance passes, the mandatory-field and totals
 * check once the number exists, the document stored in the same transaction,
 * and - after commit - invoice.issued. Queueing the send is the caller's
 * (UaeInvoiceIssuer), since a caller may issue now and send later.
 *
 * Nothing of ZATCA's runs: no counter, hash, signature, QR or chain. The
 * kill switch still lives in Fatoora, and the clearance still throws
 * FatooraException, which is why that type is caught here.
 */
class UaeJurisdiction implements IssuingJurisdiction
{
    public function __construct(
        private readonly PeppolPreparer $preparer,
        private readonly IssuanceNotifier $notifier,
        private readonly FilingClearance $clearance,
        private readonly KillSwitch $killSwitch,
    ) {}

    /** Not reached: a UAE issue is always asked as a reissue, so a repeat answers with its document. */
    public function refuseIssued(Invoice $invoice): Throwable
    {
        return new IssuanceRefusedException([__('This invoice has already been issued.')]);
    }

    public function refuseIssuedMeanwhile(Invoice $invoice): Throwable
    {
        return $this->refuseIssued($invoice);
    }

    /**
     * The guards every issuance passes: an operator's issuance stop, and an
     * organization approved and on a plan. Only for a draft - asking again
     * about an issued invoice answers with its document.
     *
     * @throws IssuanceRefusedException
     */
    public function prepare(Invoice $invoice, Organization $organization, bool $reissue): mixed
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return null;
        }

        try {
            $this->killSwitch->assertNotEnabled(KillSwitch::SWITCH_ISSUANCE, (string) $organization->id);
            $this->clearance->assertCleared($organization);
        } catch (FatooraException $e) {
            throw new IssuanceRefusedException([$e->getMessage()]);
        }

        return null;
    }

    /** An issued invoice's document, stored by the issuance that issued it. */
    public function finished(Invoice $invoice): mixed
    {
        return $this->preparer->prepare($invoice);
    }

    public function beforeNumbering(Invoice $invoice, mixed $context): void {}

    /**
     * Checked here, once the number exists, because the document carries it.
     * A refusal rolls the number and the moment of issue back with the
     * transaction, so a refused invoice stays a draft without either.
     *
     * @throws IssuanceRefusedException
     */
    public function seal(Invoice $invoice, Organization $organization, mixed $context): Seal
    {
        $problems = $this->problems($invoice);

        if ($problems !== []) {
            throw new IssuanceRefusedException($problems);
        }

        return new Seal;
    }

    /** The document, stored in the transaction that issues the invoice. */
    public function afterIssued(Invoice $invoice, mixed $context, Seal $seal): mixed
    {
        return $this->preparer->prepare($invoice);
    }

    /**
     * invoice.issued carries the e-invoice as it stands at issue, from the
     * document already in hand: the UAE has no counter or ZATCA identifier
     * to report, and its amounts are in the invoice's own currency.
     *
     * @param  PeppolDocument  $result
     */
    public function afterCommit(Invoice $invoice, mixed $result, bool $issuedNow): void
    {
        if ($issuedNow) {
            $this->notifier->issued($invoice, ['e_invoice' => [
                'id' => $result->id,
                'status' => $result->status,
                'message_id' => $result->asp_message_id,
            ]]);
        }
    }

    /**
     * What would stop the invoice being sent: the mandatory fields, then
     * whether the document would say what the invoice says.
     *
     * @return list<string>
     */
    private function problems(Invoice $invoice): array
    {
        $problems = $this->preparer->problems($invoice);

        return $problems !== [] ? $problems : $this->totalsDisagree($invoice);
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

<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\ParticipantDirectory;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Contracts\Seal;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuanceNotifier;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Platform\Services\KillSwitch;
use App\Support\Errors\ComplianceException;
use Throwable;

/**
 * The UAE's part of issuing an invoice: a Peppol PINT AE document.
 *
 * The shared IssuingLifecycle numbers the invoice, sets its moment of issue
 * and marks it issued under the organization's issuing lock. This adds the guards
 * every issuance passes, the mandatory-field and totals check once the
 * number exists, the document stored in the same transaction, and - after
 * commit - invoice.issued. Queueing the send is the caller's
 * (InvoiceIssuer), since a caller may issue now and send later.
 *
 * The document is the whole of the UAE's seal: there is no counter, hash,
 * signature, QR or chain. The shared kill switch and filing clearance
 * refuse with a ComplianceException, caught here and given back as the
 * UAE's IssuanceRefusedException.
 */
class IssuingStep implements IssuingJurisdiction
{
    public function __construct(
        private readonly PeppolPreparer $preparer,
        private readonly IssuanceNotifier $notifier,
        private readonly FilingClearance $clearance,
        private readonly KillSwitch $killSwitch,
        private readonly FeatureOverrides $overrides,
        private readonly ParticipantDirectory $participants,
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
            // A platform admin's switch for this organization alone
            // (docs/architecture/ACCESS.md).
            $this->overrides->assertNotSwitchedOff($organization, ...Feature::forDocument($invoice->type, $invoice->document_type));
        } catch (ComplianceException $e) {
            throw new IssuanceRefusedException([$e->getMessage()]);
        }

        return null;
    }

    /**
     * The organization: a UAE document has no counter or chain of a device,
     * so one organization's documents are issued one at a time, as before
     * there were scopes.
     */
    public function lockScope(Invoice $invoice, mixed $context): string
    {
        return (string) $invoice->org_id;
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
     * document already in hand: the UAE has no counter or authority
     * identifier to report, and its amounts are in the invoice's own currency.
     *
     * @param  PeppolDocument  $result
     */
    public function afterCommit(Invoice $invoice, mixed $result, bool $issuedNow): void
    {
        if ($issuedNow) {
            $this->notifier->issued($invoice, ['e_invoice' => [
                'id' => $result->id,
                'status' => $result->status,
                'reporting_status' => $result->reporting_status,
                'message_id' => $result->asp_message_id,
            ]]);
        }
    }

    /**
     * What would stop the invoice being sent: the mandatory fields, whether
     * the document would say what the invoice says, then whether its
     * recipient can be reached on the network at all.
     *
     * @return list<string>
     */
    private function problems(Invoice $invoice): array
    {
        $problems = $this->preparer->problems($invoice);
        $problems = $problems !== [] ? $problems : $this->totalsDisagree($invoice);

        return $problems !== [] ? $problems : $this->unreachable($invoice);
    }

    /**
     * The recipient's participant identifier, looked up before the invoice
     * is issued, as the sending provider looks it up before transmitting
     * (UAE Electronic Invoicing Guidelines v1.1, p11 rows 5-6; programme
     * presentation p7). Not registered: refused, with what to do instead -
     * a buyer not yet on e-invoicing has a reserved endpoint (p23). Not
     * known - no provider, or none answering - stops nothing.
     *
     * @return list<string>
     */
    private function unreachable(Invoice $invoice): array
    {
        $endpoint = $this->preparer->receiverEndpoint($invoice);

        if ($this->participants->reachable($endpoint) !== false) {
            return [];
        }

        return [__('The recipient\'s Peppol identifier :endpoint is not registered on the Peppol network, so the e-invoice cannot be delivered. Check the identifier with them. A buyer that is not on e-invoicing yet uses :reserved (UAE Electronic Invoicing Guidelines, p23).', [
            'endpoint' => $endpoint,
            'reserved' => PintAe::SCHEME.':'.PintAe::BUYER_NOT_YET_ON_EINVOICING,
        ])];
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

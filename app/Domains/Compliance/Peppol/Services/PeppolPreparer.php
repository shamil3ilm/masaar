<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Peppol\DTOs\PintDocument;
use App\Domains\Compliance\Peppol\DTOs\PintParty;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Profiles\PintAe;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Turns an issued UAE invoice into the document the transport sends.
 *
 * Map, check the mandatory fields, build the XML, store it 'ready' - and
 * whether it was issued past the 14-day deadline (IssueDeadline). A
 * document that fails the check is never stored: the caller gets every
 * problem at once, as a DomainException, and nothing half-made is left for
 * the transport to send.
 *
 * One outbound document per invoice. The invoice row is locked while the
 * existing document is looked for, since until the insert there is no
 * document row for two callers to contend on; a second call returns the
 * document the first one stored.
 */
class PeppolPreparer
{
    public const STATUS_READY = 'ready';

    public function __construct(
        private readonly PintMapper $mapper,
        private readonly PintUblBuilder $builder,
        private readonly MandatoryFields $mandatoryFields,
        private readonly PintAe $profile,
        private readonly IssueDeadline $deadline,
    ) {}

    /**
     * @throws DomainException listing what is missing; nothing is stored
     */
    public function prepare(Invoice $invoice): PeppolDocument
    {
        $existing = $this->existing($invoice);

        if ($existing !== null) {
            return $existing;
        }

        if ($invoice->status === InvoiceStatus::Draft) {
            throw new DomainException(__('A draft is not sent. Issue the invoice first.'));
        }

        $document = $this->document($invoice);
        $problems = $this->mandatoryFields->problems($document, $this->profile);

        if ($problems !== []) {
            throw new DomainException(__('The UAE e-invoice cannot be prepared:')."\n- ".implode("\n- ", $problems));
        }

        $xml = $this->builder->build($document);

        return DB::transaction(function () use ($invoice, $document, $xml): PeppolDocument {
            Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first());

            return $this->existing($invoice) ?? PeppolDocument::create([
                'org_id' => $invoice->org_id,
                'invoice_id' => $invoice->id,
                'direction' => PeppolDocument::DIRECTION_OUT,
                'country' => $this->profile->country(),
                'customization_id' => $document->customizationId,
                'profile_id' => $document->profileId,
                'document_type' => $document->category->documentType(),
                'xml' => $xml,
                'status' => self::STATUS_READY,
                'sender_endpoint' => $this->sender($document)->endpoint(),
                'receiver_endpoint' => $this->receiver($document)->endpoint(),
                'issued_late' => $this->deadline->isLate($invoice),
            ]);
        });
    }

    /**
     * What would stop the invoice being prepared, without storing anything.
     *
     * @return list<string>
     */
    public function problems(Invoice $invoice): array
    {
        try {
            return $this->mandatoryFields->problems($this->document($invoice), $this->profile);
        } catch (DomainException $e) {
            return [$e->getMessage()];
        }
    }

    /**
     * @throws DomainException when the organization is not set up for the UAE,
     *                         or the invoice is not a kind the UAE has
     */
    public function document(Invoice $invoice): PintDocument
    {
        return $this->mapper->map($invoice, $this->settings($invoice), $this->profile);
    }

    /**
     * The invoice's own profile when it is the UAE's, else the
     * organization's active UAE profile.
     */
    private function settings(Invoice $invoice): ComplianceProfile
    {
        $profile = $invoice->profile;

        if ($profile !== null && $profile->jurisdiction === $this->profile->country()) {
            return $profile;
        }

        return $invoice->org?->complianceProfileFor($this->profile->country())
            ?? throw new DomainException(__('The organization has no active UAE compliance profile.'));
    }

    private function existing(Invoice $invoice): ?PeppolDocument
    {
        return PeppolDocument::withoutTenantScope(fn (): ?PeppolDocument => PeppolDocument::query()
            ->where('invoice_id', $invoice->id)
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->first());
    }

    /** On a self-billed document the buyer sends, and the seller receives. */
    private function sender(PintDocument $document): PintParty
    {
        return $document->category->isSelfBilled() ? $document->buyer : $document->seller;
    }

    private function receiver(PintDocument $document): PintParty
    {
        return $document->category->isSelfBilled() ? $document->seller : $document->buyer;
    }
}

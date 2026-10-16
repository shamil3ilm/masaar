<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Contracts\Seal;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * ZATCA's part of issuing a Saudi invoice: the switches, clearance and rules
 * checked before a counter is spent, the device and its counter, and the seal
 * - XML, signature, QR and hash - with the chain entry that names it.
 *
 * The context it hands itself is the signing credentials prepare() found.
 */
class ZatcaJurisdiction implements IssuingJurisdiction
{
    public function __construct(
        private readonly DocumentBuilder $compliance,
        private readonly KillSwitch $killSwitch,
        private readonly FilingClearance $clearance,
        private readonly SigningCredentials $signing,
        private readonly ChainRecorder $chain,
    ) {}

    public function refuseIssued(Invoice $invoice): FatooraException
    {
        return new FatooraException(
            "Invoice {$this->label($invoice)} is {$invoice->status->value} and cannot be issued again. "
            .'Re-issuing rewrites its hash and forks the chain.',
            ErrorCode::VAL_INVOICE_ALREADY_ISSUED,
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status->value,
            ],
        );
    }

    public function refuseIssuedMeanwhile(Invoice $invoice): FatooraException
    {
        return new FatooraException(
            "Invoice {$invoice->invoice_number} was issued by another request.",
            ErrorCode::VAL_INVOICE_ALREADY_ISSUED,
            ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number],
        );
    }

    /**
     * @return array{privateKey: ?string, certificate: ?string, device: string}
     */
    public function prepare(Invoice $invoice, Organization $organization, bool $reissue): array
    {
        // Issuance is what this method does: it signs the document and marks
        // the invoice Issued, which is the irreversible step. KillSwitch offers
        // an issuance stop for a signing defect caught in production, and
        // nothing consulted it — so the switch could be thrown while documents
        // continued to be signed and issued.
        $this->killSwitch->assertNotEnabled(KillSwitch::SWITCH_ISSUANCE, (string) $organization->id);
        $this->killSwitch->assertNotEnabled(KillSwitch::SWITCH_SIGNING, (string) $organization->id);

        // Issuing gives the invoice its counter and makes it a tax invoice,
        // so an organization not yet approved - or without a plan - drafts
        // and stops there.
        $this->clearance->assertCleared($organization);

        // ZATCA's rules, before a counter is spent. InvoiceValidator held them
        // and nothing called it, so an invoice that broke them was signed,
        // numbered and chained, and only then refused by the authority - with
        // its counter gone for good. Not on a reissue, which finishes an
        // issuance that already passed here.
        if (! $reissue) {
            $check = $this->compliance->validateInvoice($invoice, $organization);

            if (! $check['valid']) {
                throw new FatooraException(
                    "Invoice {$this->label($invoice)} breaks ZATCA's rules: ".implode('; ', $check['errors']),
                    ErrorCode::VALIDATION_FAILED,
                    ['errors' => $check['errors']],
                );
            }
        }

        // The device that issues the document signs it: the invoice's branch
        // when it has a certificate of its own, the organization otherwise -
        // the same device CredentialStore::device() files it as. The branch
        // was never passed, so a branch document was signed by head office
        // and filed by the branch.
        //
        // Required once the organization is onboarded. Unsigned is Phase 1 and
        // correct before onboarding; after it, it is a document no device can
        // file, and it was issued without a word.
        return $this->signing->for(
            $organization->id,
            $this->signing->branchOf($invoice),
            required: $organization->zatca_onboarded,
        );
    }

    /**
     * @return array{hash: string, qr_code: ?string, signed_xml: ?string}|null
     */
    public function finished(Invoice $invoice): ?array
    {
        if ($invoice->icv !== null && $invoice->hash !== null) {
            return [
                'hash' => $invoice->hash,
                'qr_code' => $invoice->qr_code,
                'signed_xml' => $invoice->signed_xml,
            ];
        }

        return null;
    }

    public function beforeNumbering(Invoice $invoice, mixed $context): void
    {
        // A draft carries no counter. Anything already numbered keeps its
        // number, so re-issuing a document does not move it in the chain.
        //
        // Numbered in the sequence of the device that signs it - the
        // branch when it has its own certificate, head office otherwise -
        // because ZATCA keeps a counter and a chain per EGS unit.
        if ($invoice->icv === null) {
            $invoice->device_id = $context['device'];
            $invoice->icv = Invoice::generateNextIcv((string) $invoice->org_id, $invoice->device_id);
        }
    }

    public function seal(Invoice $invoice, Organization $organization, mixed $context): Seal
    {
        $previousHash = $invoice->previous_invoice_hash;

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            previousInvoiceHash: $previousHash,
            privateKey: $context['privateKey'] ?? null,
            certificate: $context['certificate'] ?? null,
        );

        return new Seal(
            columns: [
                'icv' => $invoice->icv,
                'device_id' => $invoice->device_id,
                'hash' => $complianceData['hash'],
                'qr_code' => $complianceData['qr_code'],
                'signed_xml' => $complianceData['signed_xml'] ?? null,
            ],
            memo: $previousHash,
        );
    }

    /**
     * @return array{hash: string, qr_code: string, signed_xml: ?string}
     */
    public function afterIssued(Invoice $invoice, mixed $context, Seal $seal): array
    {
        $this->chain->record(
            invoice: $invoice,
            invoiceHash: $seal->columns['hash'],
            previousHash: $seal->memo,
            certificate: $context['certificate'] ?? null,
        );

        return [
            'hash' => $seal->columns['hash'],
            'qr_code' => $seal->columns['qr_code'],
            'signed_xml' => $seal->columns['signed_xml'],
        ];
    }

    /**
     * Nothing: filing is the caller's to start (InvoiceIssuer, Submitter::submit).
     */
    public function afterCommit(Invoice $invoice, mixed $result, bool $issuedNow): void {}

    /**
     * How an invoice is named in a message: its number, or for a draft not
     * yet numbered, its id.
     */
    private function label(Invoice $invoice): string
    {
        return filled($invoice->invoice_number) ? (string) $invoice->invoice_number : 'draft '.$invoice->id;
    }
}

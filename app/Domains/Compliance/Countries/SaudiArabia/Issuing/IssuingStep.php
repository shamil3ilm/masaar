<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Issuing;

use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\TimestampValidator;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationCheck;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\SigningCredentials;
use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Contracts\Seal;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use App\Domains\Organization\Services\Tills;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Platform\Services\KillSwitch;
use App\Support\Errors\ErrorCode;

/**
 * ZATCA's part of issuing a Saudi invoice: the switches, clearance and rules
 * checked before a counter is spent, the device and its counter, and the seal
 * - XML, signature, QR and hash - with the chain entry that names it.
 *
 * The context it hands itself is the signing credentials prepare() found.
 */
class IssuingStep implements IssuingJurisdiction
{
    public function __construct(
        private readonly DocumentBuilder $compliance,
        private readonly KillSwitch $killSwitch,
        private readonly FilingClearance $clearance,
        private readonly SigningCredentials $signing,
        private readonly ChainRecorder $chain,
        private readonly FeatureOverrides $overrides,
        private readonly TimestampValidator $timestamps,
        private readonly RevocationCheck $revocation,
        private readonly Tills $tills,
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
        // ZATCA issues Saudi invoices. An organization registered elsewhere is
        // issued by its own country's module, through the engine, and must
        // never take a ZATCA counter, hash or signature. A blank
        // country is a row from before the column was set, which was Saudi.
        if (filled($organization->country) && $organization->country !== 'SA') {
            throw new FatooraException(
                "Invoice {$this->label($invoice)} belongs to an organization registered in {$organization->country}. "
                .'ZATCA issues Saudi invoices only; issue it through its own country\'s e-invoicing.',
                ErrorCode::VALIDATION_FAILED,
                ['invoice_id' => $invoice->id, 'country' => $organization->country],
            );
        }

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

        // A platform admin can switch issuing - or one kind of document -
        // off for this organization alone (docs/architecture/ACCESS.md).
        $this->overrides->assertNotSwitchedOff($organization, ...Feature::forDocument($invoice->type, $invoice->document_type));

        // ZATCA's rules, before a counter is spent. InvoiceValidator held them
        // and nothing called it, so an invoice that broke them was signed,
        // numbered and chained, and only then refused by the authority - with
        // its counter gone for good. Not on a reissue, which finishes an
        // issuance that already passed here.
        if (! $reissue) {
            $this->assertSaleTime($invoice);

            $check = $this->compliance->validateInvoice($invoice, $organization);

            if (! $check['valid']) {
                throw new FatooraException(
                    "Invoice {$this->label($invoice)} breaks ZATCA's rules: ".implode('; ', $check['errors']),
                    ErrorCode::VALIDATION_FAILED,
                    ['errors' => $check['errors']],
                );
            }
        }

        // The till that issues the document signs it: the one the draft
        // named, else its branch's default till - head office's when the
        // branch has none, or none with a certificate of its own - the same
        // unit CredentialStore::forInvoice() files it as. A retired till is
        // refused here, before a counter is spent.
        //
        // Required once the organization is onboarded. Unsigned is Phase 1 and
        // correct before onboarding; after it, it is a document no device can
        // file, and it was issued without a word.
        $credentials = $this->signing->forInvoice($invoice, required: $organization->zatca_onboarded);

        // Before a counter is spent: a certificate is checked against its
        // CA's revocation list before it stamps (Security Features
        // Implementation Standard v1.2, section 2.2.1, requirement 14).
        if (! empty($credentials['certificate'])) {
            $this->revocation->assertMayStamp((string) $credentials['certificate']);
        }

        return $credentials;
    }

    /**
     * The device: ZATCA keeps one counter and one chain per EGS unit, so a
     * device issues one document at a time and devices issue at once. A
     * document already placed in a chain - a reissue finishing what was
     * interrupted - stays in the one it was placed in.
     */
    public function lockScope(Invoice $invoice, mixed $context): string
    {
        return (string) ($invoice->device_id ?? $context['device']);
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
        // Numbered in the sequence of the till that signs it - its own
        // when it holds a certificate, head office's otherwise - because
        // ZATCA keeps a counter and a chain per EGS unit.
        if ($invoice->icv === null) {
            // Asked again under the till's lock: a till retired while this
            // waited for it - UnitRetirement takes the same lock - takes no
            // new counter.
            $this->assertStillIssues((string) $invoice->org_id, (string) $context['device']);

            $invoice->device_id = $context['device'];
            $invoice->icv = Invoice::generateNextIcv((string) $invoice->org_id, $invoice->device_id);

            // "Invoice Issue Date must be the date on which final Tax Invoice
            // is generated" (ZATCA Detailed Guidelines for E-Invoicing, p60):
            // the date and time of issue - IssueDate, IssueTime and the QR's
            // timestamp - are now, on the Kingdom's clock, whatever date the
            // draft or the till's sale carried. The sale is the supply date.
            $invoice->issued_at = now();
            $invoice->issue_date = FatooraTime::toSaudiTime($invoice->issued_at)->format('Y-m-d');
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
     * A sale the till says it made in the future is refused, before a
     * counter is spent, so it stays a draft with nothing taken. A late one
     * never is (TimestampValidator::saleTimeProblem).
     *
     * @throws FatooraException
     */
    private function assertSaleTime(Invoice $invoice): void
    {
        if ($invoice->sold_at === null) {
            return;
        }

        $problem = $this->timestamps->saleTimeProblem($invoice->sold_at);

        if ($problem !== null) {
            throw new FatooraException(
                "Invoice {$this->label($invoice)}: {$problem}",
                ErrorCode::VALIDATION_FAILED,
                ['sold_at' => $invoice->sold_at->toIso8601String()],
            );
        }
    }

    /**
     * @throws FatooraException when the till no longer issues
     */
    private function assertStillIssues(string $organizationId, string $deviceId): void
    {
        $unit = $this->tills->find($organizationId, $deviceId);

        if ($unit !== null && ! $unit->issues()) {
            throw new FatooraException(
                __('Till :till no longer issues invoices (:status). Issue from another till.', ['till' => $unit->label(), 'status' => $unit->statusLabel()]),
                ErrorCode::VALIDATION_FAILED,
                ['device_id' => $deviceId, 'status' => $unit->status],
            );
        }
    }

    /**
     * How an invoice is named in a message: its number, or for a draft not
     * yet numbered, its id.
     */
    private function label(Invoice $invoice): string
    {
        return filled($invoice->invoice_number) ? (string) $invoice->invoice_number : 'draft '.$invoice->id;
    }
}

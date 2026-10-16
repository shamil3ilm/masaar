<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Helpers\CertificatePem;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceNumbering;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ZATCA submission service.
 *
 * Orchestrates the full invoice submission workflow:
 * - Generate compliance data
 * - Choose clearance vs reporting
 * - Submit to ZATCA
 * - Update invoice state
 *
 * This is the single entry point for ZATCA submissions.
 */
class Submitter
{
    /**
     * Every dependency is required, none optional.
     *
     * The container skips optional constructor parameters, so an optional
     * BranchService resolves to null and the branch credential lookup below
     * becomes unreachable — signing a branch's invoice with the organization's
     * certificate, which ZATCA reads as a different device. A missing binding
     * should fail at boot instead.
     */
    public function __construct(
        private readonly DocumentBuilder $compliance,
        private readonly FatooraClient $client,
        private readonly AuditService $audit,
        private readonly CertificateService $certificateService,
        private readonly BranchService $branchService,
        private readonly CredentialStore $credentials,
        private readonly KillSwitch $killSwitch,
        private readonly ChainRecorder $chain,
        private readonly InvoiceVerdict $verdict,
        private readonly InvoiceNumbering $numbering,
        private readonly FilingClearance $clearance,
    ) {}

    /**
     * Generate compliance data and issue invoice.
     *
     * @param  bool  $reissue  Allow a document that is no longer a draft to be
     *                         signed again. Only for a caller completing an
     *                         issuance that did not finish.
     * @return array{hash: string, qr_code: string}
     *
     * @throws FatooraException If the invoice is already issued and $reissue is false
     */
    public function generate(Invoice $invoice, Organization $organization, bool $reissue = false): array
    {
        // Re-issuing rewrites the hash and, through ChainRecorder's
        // updateOrCreate, the chain entry built from it - while the next
        // invoice's entry still names the old hash. The chain forks, nothing
        // errors, and the stored document stops matching what the authority
        // holds. A double-clicked button is enough.
        //
        // The opt-in exists for two internal callers that re-enter only when
        // the hash is missing, which is finishing an issuance rather than
        // repeating one.
        if (! $reissue && $invoice->status !== InvoiceStatus::Draft) {
            throw new FatooraException(
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
        $credentials = $this->getSigningCredentials(
            $organization->id,
            $this->branchOf($invoice),
            required: $organization->zatca_onboarded,
        );

        // Counter, predecessor, document and chain entry are decided together,
        // inside one transaction holding the organization row.
        //
        // Reading the PIH outside this block is what let the chain fork: two
        // issuances for one tenant both read the head before either wrote, and
        // both claimed it. Allocating the counter here as well closes the
        // other way in — a document issued ahead of a lower-numbered draft,
        // which needs no concurrency at all to produce the same break.
        //
        // The lock is the organization row rather than the invoice rows, for
        // the reason generateNextIcv() gives: there are no invoice rows to
        // lock for a tenant's first document, so two requests would both find
        // nothing and both allocate 1. The organization row always exists.
        //
        // Signing happens under that lock, which is the cost. It serialises
        // issuance per tenant — correct, and worth measuring if a tenant ever
        // issues fast enough to feel it. An advisory lock keyed on org_id
        // would free the row without changing the guarantee.
        $complianceData = DB::transaction(function () use ($invoice, $organization, $credentials, $reissue): array {
            DB::table('organizations')
                ->where('id', $invoice->org_id)
                ->lockForUpdate()
                ->first();

            // Decided again here, on the row as it stands. The check above
            // read the caller's copy before the lock: two requests issuing one
            // draft both saw a draft, took the lock in turn, and the second -
            // still holding icv = null - allocated another counter and
            // re-signed a document already issued. Every issuance holds this
            // lock, so the row read under it is final.
            $invoice->refresh();

            if ($invoice->status !== InvoiceStatus::Draft) {
                if (! $reissue) {
                    throw new FatooraException(
                        "Invoice {$invoice->invoice_number} was issued by another request.",
                        ErrorCode::VAL_INVOICE_ALREADY_ISSUED,
                        ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number],
                    );
                }

                // Asked to finish an issuance another request has finished:
                // there is nothing left to do, and re-signing would be a
                // second document under the same counter.
                if ($invoice->icv !== null && $invoice->hash !== null) {
                    return [
                        'hash' => $invoice->hash,
                        'qr_code' => $invoice->qr_code,
                        'signed_xml' => $invoice->signed_xml,
                    ];
                }
            }

            // A draft carries no counter. Anything already numbered keeps its
            // number, so re-issuing a document does not move it in the chain.
            //
            // Numbered in the sequence of the device that signs it - the
            // branch when it has its own certificate, head office otherwise -
            // because ZATCA keeps a counter and a chain per EGS unit.
            if ($invoice->icv === null) {
                $invoice->device_id = $credentials['device'];
                $invoice->icv = Invoice::generateNextIcv((string) $invoice->org_id, $invoice->device_id);
            }

            // A draft left unnumbered takes its number now, under the same
            // lock as the counter: issued invoices run without gaps, and a
            // failure from here rolls the number back with the counter.
            if (blank($invoice->invoice_number)) {
                $invoice->invoice_number = $this->numbering->allocate($invoice);
            }

            // The moment of issue, set once: a reissue that finishes an
            // interrupted issuance keeps the time the document was given.
            if ($invoice->issued_at === null) {
                $invoice->issued_at = now();
            }

            $previousHash = $invoice->previous_invoice_hash;

            $complianceData = $this->compliance->generateComplianceData(
                invoice: $invoice,
                organization: $organization,
                previousInvoiceHash: $previousHash,
                privateKey: $credentials['privateKey'] ?? null,
                certificate: $credentials['certificate'] ?? null,
            );

            $invoice->update([
                'invoice_number' => $invoice->invoice_number,
                'icv' => $invoice->icv,
                'device_id' => $invoice->device_id,
                'issued_at' => $invoice->issued_at,
                'hash' => $complianceData['hash'],
                'qr_code' => $complianceData['qr_code'],
                'signed_xml' => $complianceData['signed_xml'] ?? null,
                'status' => InvoiceStatus::Issued,
            ]);

            $this->chain->record(
                invoice: $invoice,
                invoiceHash: $complianceData['hash'],
                previousHash: $previousHash,
                certificate: $credentials['certificate'] ?? null,
            );

            return $complianceData;
        });

        return [
            'hash' => $complianceData['hash'],
            'qr_code' => $complianceData['qr_code'],
            'signed_xml' => $complianceData['signed_xml'] ?? null,
        ];
    }

    /**
     * How an invoice is named in a message: its number, or for a draft not
     * yet numbered, its id.
     */
    private function label(Invoice $invoice): string
    {
        return filled($invoice->invoice_number) ? (string) $invoice->invoice_number : 'draft '.$invoice->id;
    }

    /**
     * Validate invoice with ZATCA (without submission).
     */
    public function validate(Invoice $invoice, Organization $organization): FatooraResponse
    {
        $previousHash = $invoice->previous_invoice_hash;
        $credentials = $this->getSigningCredentials($organization->id, $this->branchOf($invoice));

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            previousInvoiceHash: $previousHash,
            privateKey: $credentials['privateKey'] ?? null,
            certificate: $credentials['certificate'] ?? null,
        );

        return $this->client->checkCompliance(
            invoiceXml: $complianceData['xml'],
            invoiceHash: $complianceData['hash'],
            uuid: $invoice->id,
            // ZATCA's compliance endpoint takes the compliance CSID, not the
            // production one.
            device: $this->credentials->device((string) $invoice->org_id, $invoice->branch_id, CredentialStore::CCSID),
        );
    }

    /**
     * Submit invoice to ZATCA (clearance or reporting).
     *
     * COMPLIANCE: Validates license environment matches ZATCA environment.
     * Sandbox licenses cannot submit to production ZATCA.
     *
     * Supports both branch-level and organization-level credentials.
     * If invoice has a branch_id, uses branch credentials; otherwise falls back to org credentials.
     *
     * @throws FatooraException If organization not onboarded or credentials missing
     */
    public function submit(Invoice $invoice, Organization $organization): FatooraResponse
    {
        // CRITICAL: Validate organization has completed ZATCA onboarding
        $this->validateOnboarding($organization);

        // Validate environment before submission
        $this->validateEnvironment();

        // Issuance is what allocates the counter and fixes the predecessor,
        // and generate() is the only place that happens. A document submitted
        // without having been issued used to reach the authority carrying
        // ICV 0 and the genesis PIH, because this method builds its own
        // document and nothing here had assigned either.
        //
        // Calling it here rather than throwing keeps the direct submit path
        // working for callers that never asked for a separate issuance step.
        //
        // The opt-in is passed because this reaches generate() only when the
        // counter or the hash is missing, so the document was never fully
        // issued - a half-finished issuance is being completed, not repeated,
        // and there is no hash for a second one to overwrite.
        if ($invoice->icv === null || $invoice->hash === null) {
            $this->generate($invoice, $organization, reissue: true);
            $invoice->refresh();
        }

        // A branch signs with its own credentials; an invoice that names no
        // branch signs with the organization's. invoices.branch_id is nullable
        // rather than absent, so isset() is asking whether this document came
        // from a particular EGS unit, not whether the column exists.
        $branch = $this->branchOf($invoice);
        $credentials = $this->getSigningCredentials($organization->id, $branch, required: true);

        // Validate certificate is valid and not revoked before submission
        $this->validateCertificate($credentials['certificate']);

        // Validate branch is active if invoice has branch
        if ($branch && ! $branch->isFatooraReady()) {
            throw FatooraException::notOnboarded(
                'Branch is not ready for invoice submission. '.
                'Status: '.$branch->onboarding_status,
                [
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'onboarding_status' => $branch->onboarding_status,
                ]
            );
        }

        // The document that was issued, not a new one.
        //
        // This used to call generateComplianceData() a second time and send
        // that. Signing again produces a different XAdES SigningTime, so the
        // bytes ZATCA saw were never the bytes on the invoice — and any input
        // that had moved since issuance made them differ in ways that matter.
        // The archive is the evidence of what was issued; if it does not match
        // what the authority holds, it is evidence of something else.
        //
        // Issuance above guarantees both are present.
        $invoiceXml = (string) $invoice->signed_xml;
        $invoiceHash = (string) $invoice->hash;
        $device = $this->credentials->forInvoice((string) $invoice->org_id, $invoice->device_id, $invoice->branch_id);

        // Choose clearance (B2B) or reporting (B2C) based on invoice type
        if ($invoice->requiresClearance()) {
            // B2B: Submit for clearance (no deadline)
            $response = $this->client->clearInvoice(
                invoiceXml: $invoiceXml,
                invoiceHash: $invoiceHash,
                uuid: $invoice->id,
                device: $device,
            );
        } else {
            // B2C: report. ZATCA gives it 24 hours, and a late report is still
            // owed - the overdue sweep and the deadline screen say it is late;
            // refusing to send it would only make sure it never arrives.
            $response = $this->client->reportInvoice(
                invoiceXml: $invoiceXml,
                invoiceHash: $invoiceHash,
                uuid: $invoice->id,
                device: $device,
            );
        }

        // The invoice's status, its branch's count and the audit entry describe
        // one answer, so they commit together or not at all.
        DB::transaction(function () use ($invoice, $response): void {
            $this->verdict->record($invoice, $response);

            $this->audit->logZatcaSubmission($invoice, $response->success, [
                'clearance_status' => $response->clearanceStatus,
                'reporting_status' => $response->reportingStatus,
                'errors' => $response->errorMessages,
            ]);
        });

        return $response;
    }

    /**
     * Validate certificate is valid and not revoked.
     *
     * COMPLIANCE: Checks certificate expiry and revocation status before submission.
     * Prevents submission with expired or revoked certificates.
     *
     * @throws FatooraException If certificate is invalid, expired, or revoked
     */
    private function validateCertificate(?string $certificate): void
    {
        if (empty($certificate)) {
            return; // Already handled by getSigningCredentials
        }

        // Check if certificate validation is enabled
        if (! config('fatoora.features.certificate_revocation_check', true)) {
            return;
        }

        try {
            $validation = $this->certificateService->validateForSubmission($certificate);

            if (! $validation['valid']) {
                $errors = $validation['errors'] ?? [];
                throw FatooraException::certificate(
                    'Certificate validation failed: '.implode('; ', $errors),
                    context: [
                        'errors' => $errors,
                        'warnings' => $validation['warnings'] ?? [],
                        'days_until_expiry' => $validation['days_until_expiry'] ?? null,
                    ]
                );
            }

            // Log warning if certificate is expiring soon
            $daysUntilExpiry = $validation['days_until_expiry'] ?? null;
            if ($daysUntilExpiry !== null && $daysUntilExpiry <= 30) {
                Log::warning('Certificate expiring soon', [
                    'days_until_expiry' => $daysUntilExpiry,
                    'warnings' => $validation['warnings'] ?? [],
                ]);
            }
        } catch (FatooraException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Log but don't block submission if validation fails unexpectedly
            Log::warning('Certificate validation failed unexpectedly', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate organization has completed ZATCA onboarding.
     *
     * COMPLIANCE: Organizations must complete the full onboarding process
     * (CSR generation, compliance check, PCSID acquisition) before submitting invoices.
     *
     * @throws FatooraException If organization not onboarded
     */
    private function validateOnboarding(Organization $organization): void
    {
        if (! $organization->zatca_onboarded) {
            throw FatooraException::notOnboarded(
                'Organization has not completed ZATCA onboarding. '.
                'Complete the 3-step onboarding process before submitting invoices: '.
                '1) Generate CSR and get CCSID, 2) Pass compliance checks, 3) Get PCSID.',
                [
                    'org_id' => $organization->id,
                    'zatca_onboarded' => false,
                ]
            );
        }
    }

    /**
     * Validate license environment matches ZATCA environment.
     *
     * COMPLIANCE: Prevents sandbox API keys from submitting to production ZATCA.
     * This is a critical safety check to avoid test data in production.
     *
     * @throws FatooraException If environment mismatch detected
     */
    private function validateEnvironment(): void
    {
        $zatcaEnvironment = $this->client->getEnvironment();

        // If ZATCA is configured for production, verify license allows production
        if ($zatcaEnvironment === 'production') {
            $license = request()->attributes->get('license');

            if ($license !== null) {
                $licenseEnv = $license->environment;

                // Sandbox licenses cannot submit to production ZATCA
                if ($licenseEnv === LicenseEnvironment::Sandbox) {
                    throw FatooraException::environmentMismatch(
                        'Sandbox API keys cannot submit invoices to production ZATCA. '.
                        'Please use a production API key (cp_live_*) for real invoice submissions.',
                        [
                            'license_environment' => $licenseEnv->value,
                            'zatca_environment' => $zatcaEnvironment,
                        ]
                    );
                }
            }
        }

        // If license is production but ZATCA is sandbox, log warning but allow
        // (useful for testing production keys against sandbox)
        $license = request()->attributes->get('license');
        if ($license !== null && $license->environment === LicenseEnvironment::Production) {
            if ($zatcaEnvironment === 'sandbox') {
                Log::info('Production license submitting to sandbox ZATCA', [
                    'license_id' => $license->id,
                    'zatca_environment' => $zatcaEnvironment,
                ]);
            }
        }
    }

    /**
     * The certificate this invoice is signed with.
     *
     * Credentials live at one of two levels. BranchOnboardingController
     * stores them under a branch; OnboardingController stores them under the
     * organization, with a null branch id.
     *
     * A branch is its own EGS unit holding its own certificate, so an invoice
     * that names a branch signs with that branch's. An organization that has
     * not divided itself into EGS units has a single certificate and no branch
     * to hold it, and its invoices sign with that.
     *
     * @param  string  $organizationId  The organization ID
     * @param  Branch|null  $branch  The branch (optional)
     * @param  bool  $required  If true, throws exception when credentials are missing
     * @return array{privateKey: ?string, certificate: ?string, device: string}
     *
     * @throws FatooraException If required is true and credentials are missing/invalid
     */
    /**
     * The EGS unit an invoice came from, or null for head office.
     *
     * invoices.branch_id is nullable rather than absent, so isset() asks
     * whether this document came from a particular device.
     */
    private function branchOf(Invoice $invoice): ?Branch
    {
        return isset($invoice->branch_id) ? $invoice->branch : null;
    }

    private function getSigningCredentials(string $organizationId, ?Branch $branch = null, bool $required = false): array
    {
        // A branch is an EGS unit with its own certificate, so its credentials
        // take precedence over the organization's.
        if ($branch) {
            $branchCredentials = $this->branchService->getCredentials($branch, 'pcsid');

            if ($branchCredentials) {
                $privateKey = $branchCredentials['privateKey'] ?? null;
                $certificate = CertificatePem::from($branchCredentials['pcsid'] ?? null);

                if (! empty($privateKey) && ! empty($certificate)) {
                    Log::debug('Using branch-level credentials', [
                        'org_id' => $organizationId,
                        'branch_id' => $branch->id,
                    ]);

                    return [
                        'privateKey' => $privateKey,
                        'certificate' => $certificate,
                        // The branch is the device: its own counter and chain.
                        'device' => (string) $branch->id,
                    ];
                }
            }
        }

        // The organization's own certificate: used when the invoice names no
        // branch, or the branch it names holds no credentials of its own.
        $path = "zatca/{$organizationId}/pcsid.json";
        $organizationCredentials = $this->credentials->get($organizationId, null, CredentialStore::PCSID);

        if ($organizationCredentials === null) {
            if ($required) {
                // Not the path. FatooraException context is returned to the
                // API caller as well as written to the log, so this disclosed
                // where the taxpayer's signing key is kept on disk to a
                // partner ERP. Which organization has no credential is the
                // actionable part; where it would have been is not.
                $errorContext = [
                    'org_id' => $organizationId,
                ];

                if ($branch) {
                    $errorContext['branch_id'] = $branch->id;
                    $errorContext['branch_name'] = $branch->name;
                }

                throw FatooraException::missingCredentials(
                    'PCSID credentials not found. '.
                    ($branch ? 'Branch' : 'Organization').' must complete ZATCA onboarding '.
                    'to obtain Production CSID (PCSID) before submitting invoices.',
                    $errorContext
                );
            }

            return ['privateKey' => null, 'certificate' => null, 'device' => $organizationId];
        }

        try {
            $data = $organizationCredentials;

            $privateKey = $data['privateKey'] ?? null;
            $certificate = CertificatePem::from($data['pcsid'] ?? null);

            // Validate credentials are not empty when required
            if ($required && (empty($privateKey) || empty($certificate))) {
                throw FatooraException::invalidCredentials(
                    'PCSID credentials are incomplete or corrupted. '.
                    'Please re-run Step 3 of ZATCA onboarding to obtain valid PCSID.',
                    [
                        'org_id' => $organizationId,
                        'has_private_key' => ! empty($privateKey),
                        'has_certificate' => ! empty($certificate),
                    ]
                );
            }

            Log::debug('Using organization-level credentials', [
                'org_id' => $organizationId,
            ]);

            return [
                'privateKey' => $privateKey,
                'certificate' => $certificate,
                'device' => $organizationId,
            ];
        } catch (FatooraException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($required) {
                throw FatooraException::invalidCredentials(
                    'Failed to decrypt PCSID credentials. The credentials may be corrupted '.
                    'or the application encryption key has changed.',
                    [
                        'org_id' => $organizationId,
                        'error' => $e->getMessage(),
                    ]
                );
            }

            return ['privateKey' => null, 'certificate' => null, 'device' => $organizationId];
        }
    }
}

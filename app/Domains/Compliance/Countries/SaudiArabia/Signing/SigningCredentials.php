<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Services\Tills;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\Log;

/**
 * The key and certificate a document is signed with, and the EGS unit - the
 * till - it is signed as. Issuing (IssuingStep) and submitting (Submitter)
 * both ask.
 */
class SigningCredentials
{
    /**
     * Every dependency is required, none optional: the container skips
     * optional constructor parameters, and a missing one would sign a till's
     * invoice with head office's certificate - which ZATCA reads as a
     * different device.
     */
    public function __construct(
        private readonly Tills $tills,
        private readonly CredentialStore $credentials,
    ) {}

    /**
     * Where a document's credentials are kept in the credential store: the
     * unit it was issued on - null for head office - or, before it is
     * issued, the branch's first till, which falls back to head office as
     * the signing path does.
     */
    public static function keyOf(Invoice $invoice): ?string
    {
        if ($invoice->device_id !== null) {
            return $invoice->device_id === $invoice->org_id ? null : (string) $invoice->device_id;
        }

        return $invoice->branch_id;
    }

    /**
     * The till this invoice is, or will be, issued on.
     *
     * A document already in a chain - a reissue finishing what was
     * interrupted - stays on the unit it was placed on, and a draft that
     * named its till is issued there. Otherwise its branch's default till,
     * else head office's (Tills::defaultFor).
     *
     * @throws FatooraException when the till it names is not the organization's
     */
    public function unitOf(Invoice $invoice): EgsUnit
    {
        $organizationId = (string) $invoice->org_id;

        if ($invoice->device_id === null) {
            return $this->tills->defaultFor($organizationId, $invoice->branch_id);
        }

        return $this->tills->find($organizationId, (string) $invoice->device_id)
            ?? throw new FatooraException(
                'The till this invoice names is not one of the organization\'s.',
                ErrorCode::VALIDATION_FAILED,
                ['invoice_id' => $invoice->id, 'device_id' => $invoice->device_id],
            );
    }

    /**
     * The credentials a document is signed with, and the unit it is signed
     * as.
     *
     * A till that issues a new document must still be issuing: a retired
     * one never issues again, and a retiring one only files what it signed.
     * A document already counted finishes on its own unit whatever its
     * status.
     *
     * A till it named itself signs with that till's certificate and nothing
     * else. One it came to by default and that holds no certificate of its
     * own is signed by head office, as a branch without a device of its own
     * always was.
     *
     * @return array{privateKey: ?string, certificate: ?string, device: string}
     *
     * @throws FatooraException when the till may not issue, or required credentials are missing
     */
    public function forInvoice(Invoice $invoice, bool $required = false): array
    {
        $unit = $this->unitOf($invoice);

        if ($invoice->icv === null && ! $unit->issues()) {
            throw new FatooraException(
                __('Till :till no longer issues invoices (:status). Issue from another till.', [
                    'till' => $unit->label(),
                    'status' => $unit->statusLabel(),
                ]),
                ErrorCode::VALIDATION_FAILED,
                ['invoice_id' => $invoice->id, 'device_id' => $unit->id, 'status' => $unit->status],
            );
        }

        return $this->for((string) $invoice->org_id, $unit, $required, fallback: $invoice->device_id === null);
    }

    /**
     * The credentials of one unit.
     *
     * Credentials live under the unit in the credential store: head office's
     * directly under the organization, any other till's under its own id
     * (OnboardingController and the unit onboarding write them).
     *
     * @param  bool  $fallback  sign as head office when the unit holds no certificate of its own
     * @return array{privateKey: ?string, certificate: ?string, device: string}
     *
     * @throws FatooraException If required is true and credentials are missing/invalid
     */
    public function for(string $organizationId, ?EgsUnit $unit = null, bool $required = false, bool $fallback = true): array
    {
        if ($unit !== null && ! $unit->isHeadOffice()) {
            $own = $this->credentials->get($organizationId, (string) $unit->id, CredentialStore::PCSID);
            $privateKey = $own['privateKey'] ?? null;
            $certificate = CertificatePem::from($own['pcsid'] ?? null);

            if (! empty($privateKey) && ! empty($certificate)) {
                Log::debug('Using till credentials', ['org_id' => $organizationId, 'device_id' => $unit->id]);

                // The till is the device: its own counter and chain.
                return ['privateKey' => $privateKey, 'certificate' => $certificate, 'device' => (string) $unit->id];
            }

            // A till whose own certificate was revoked must not fall back to
            // head office's and sign as another device.
            $this->credentials->assertNotRevoked($organizationId, (string) $unit->id);

            if (! $fallback) {
                if ($required) {
                    throw FatooraException::missingCredentials(
                        'PCSID credentials not found. Till '.$unit->label().' must complete ZATCA onboarding '
                        .'to obtain its Production CSID (PCSID) before it issues invoices.',
                        ['org_id' => $organizationId, 'device_id' => $unit->id],
                    );
                }

                return ['privateKey' => null, 'certificate' => null, 'device' => (string) $unit->id];
            }
        }

        return $this->headOffice($organizationId, $unit, $required);
    }

    /**
     * Head office's own certificate: for a document of no branch, or of a
     * branch whose till holds none of its own.
     *
     * @return array{privateKey: ?string, certificate: ?string, device: string}
     */
    private function headOffice(string $organizationId, ?EgsUnit $asked, bool $required): array
    {
        $organizationCredentials = $this->credentials->get($organizationId, null, CredentialStore::PCSID);

        if ($organizationCredentials === null) {
            // Revoked is its own reason, and not one to sign unsigned past.
            $this->credentials->assertNotRevoked($organizationId, null);

            if ($required) {
                // Not the path. FatooraException context is returned to the
                // API caller as well as written to the log, so this disclosed
                // where the taxpayer's signing key is kept on disk to a
                // partner ERP. Which organization has no credential is the
                // actionable part; where it would have been is not.
                $errorContext = ['org_id' => $organizationId];

                if ($asked !== null && ! $asked->isHeadOffice()) {
                    $errorContext['device_id'] = $asked->id;
                    $errorContext['branch_id'] = $asked->branch_id;
                }

                throw FatooraException::missingCredentials(
                    'PCSID credentials not found. '.
                    ($asked !== null && ! $asked->isHeadOffice() ? 'Till' : 'Organization').' must complete ZATCA onboarding '.
                    'to obtain Production CSID (PCSID) before submitting invoices.',
                    $errorContext
                );
            }

            return ['privateKey' => null, 'certificate' => null, 'device' => $organizationId];
        }

        try {
            $privateKey = $organizationCredentials['privateKey'] ?? null;
            $certificate = CertificatePem::from($organizationCredentials['pcsid'] ?? null);

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

            Log::debug('Using organization-level credentials', ['org_id' => $organizationId]);

            return ['privateKey' => $privateKey, 'certificate' => $certificate, 'device' => $organizationId];
        } catch (ComplianceException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($required) {
                throw FatooraException::invalidCredentials(
                    'Failed to decrypt PCSID credentials. The credentials may be corrupted '.
                    'or the application encryption key has changed.',
                    ['org_id' => $organizationId, 'error' => $e->getMessage()]
                );
            }

            return ['privateKey' => null, 'certificate' => null, 'device' => $organizationId];
        }
    }
}

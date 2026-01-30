<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Client\ZatcaClient;
use App\Domains\Compliance\Zatca\DTOs\CsrData;
use App\Domains\Compliance\Zatca\Exceptions\CertificateException;
use Illuminate\Support\Facades\Log;

/**
 * CSID Onboarding Service.
 *
 * Handles the complete ZATCA onboarding flow:
 * 1. Generate CSR with organization details
 * 2. Request Compliance CSID (CCSID) using OTP
 * 3. Run compliance checks
 * 4. Request Production CSID (PCSID)
 */
class CsidOnboardingService
{
    public function __construct(
        private readonly CertificateService $certificateService,
        private readonly ZatcaClient $client,
    ) {}

    /**
     * Step 1: Generate CSR and request Compliance CSID.
     *
     * @param CsrData $csrData CSR configuration
     * @param string $otp One-time password from ZATCA portal (valid 1 hour)
     * @return array{ccsid: string, privateKey: string, requestId: string}
     * @throws CertificateException
     */
    public function requestComplianceCsid(CsrData $csrData, string $otp): array
    {
        // Generate CSR
        $csrResult = $this->certificateService->generateCsr($csrData);

        try {
            // Call ZATCA compliance CSID endpoint
            $response = $this->client->requestComplianceCsid(
                csr: $csrResult['csr'],
                otp: $otp
            );

            if (! $response->success) {
                throw new CertificateException(
                    'Failed to obtain CCSID: ' . implode(', ', $response->errorMessages)
                );
            }

            Log::info('Compliance CSID obtained', [
                'requestId' => $response->requestId,
            ]);

            return [
                'ccsid' => $response->binarySecurityToken,
                'secret' => $response->secret,
                'privateKey' => $csrResult['privateKey'],
                'requestId' => $response->requestId,
            ];

        } catch (\Exception $e) {
            Log::error('CCSID request failed', [
                'error' => $e->getMessage(),
            ]);
            throw new CertificateException('CCSID request failed: ' . $e->getMessage());
        }
    }

    /**
     * Step 2: Run compliance checks with test invoices.
     *
     * @param string $ccsid Compliance CSID (base64 encoded certificate)
     * @param string $secret CCSID secret for authentication
     * @param array $testInvoices Array of test invoice XMLs
     * @return array{passed: bool, results: array}
     */
    public function runComplianceChecks(string $ccsid, string $secret, array $testInvoices): array
    {
        $results = [];
        $allPassed = true;

        foreach ($testInvoices as $type => $invoiceXml) {
            $response = $this->client->submitComplianceInvoice(
                invoiceXml: $invoiceXml,
                ccsid: $ccsid,
                secret: $secret
            );

            $results[$type] = [
                'passed' => $response->success,
                'status' => $response->validationStatus,
                'warnings' => $response->warningMessages,
                'errors' => $response->errorMessages,
            ];

            if (! $response->success) {
                $allPassed = false;
            }
        }

        Log::info('Compliance checks completed', [
            'passed' => $allPassed,
            'results' => $results,
        ]);

        return [
            'passed' => $allPassed,
            'results' => $results,
        ];
    }

    /**
     * Step 3: Request Production CSID after passing compliance.
     *
     * @param string $ccsid Compliance CSID
     * @param string $secret CCSID secret
     * @param string $requestId Original CCSID request ID
     * @return array{pcsid: string, secret: string}
     * @throws CertificateException
     */
    public function requestProductionCsid(string $ccsid, string $secret, string $requestId): array
    {
        try {
            $response = $this->client->requestProductionCsid(
                ccsid: $ccsid,
                secret: $secret,
                requestId: $requestId
            );

            if (! $response->success) {
                throw new CertificateException(
                    'Failed to obtain PCSID: ' . implode(', ', $response->errorMessages)
                );
            }

            Log::info('Production CSID obtained', [
                'requestId' => $response->requestId,
            ]);

            return [
                'pcsid' => $response->binarySecurityToken,
                'secret' => $response->secret,
            ];

        } catch (\Exception $e) {
            Log::error('PCSID request failed', [
                'error' => $e->getMessage(),
            ]);
            throw new CertificateException('PCSID request failed: ' . $e->getMessage());
        }
    }

    /**
     * Complete onboarding flow (all steps).
     *
     * @param CsrData $csrData CSR configuration
     * @param string $otp One-time password from ZATCA portal
     * @param array $testInvoices Test invoices for compliance check
     * @return array{pcsid: string, secret: string, privateKey: string}
     * @throws CertificateException
     */
    public function completeOnboarding(CsrData $csrData, string $otp, array $testInvoices): array
    {
        // Step 1: Get CCSID
        $ccsidResult = $this->requestComplianceCsid($csrData, $otp);

        // Step 2: Run compliance checks
        $complianceResult = $this->runComplianceChecks(
            $ccsidResult['ccsid'],
            $ccsidResult['secret'],
            $testInvoices
        );

        if (! $complianceResult['passed']) {
            throw new CertificateException(
                'Compliance checks failed. Fix issues and retry.'
            );
        }

        // Step 3: Get PCSID
        $pcsidResult = $this->requestProductionCsid(
            $ccsidResult['ccsid'],
            $ccsidResult['secret'],
            $ccsidResult['requestId']
        );

        return [
            'pcsid' => $pcsidResult['pcsid'],
            'secret' => $pcsidResult['secret'],
            'privateKey' => $ccsidResult['privateKey'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Http\Controllers;

use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Fatoora\Services\CsidOnboardingService;
use App\Domains\Compliance\Fatoora\Services\XmlBuilder;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Branch ZATCA Onboarding API controller.
 *
 * Handles ZATCA onboarding for individual branches (EGS units).
 * Each branch goes through the 3-step onboarding process independently.
 */
class BranchOnboardingController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BranchService $branchService,
        private readonly CsidOnboardingService $onboarding,
        private readonly XmlBuilder $xmlBuilder,
    ) {}

    /**
     * Step 1: Request Compliance CSID for branch.
     *
     * POST /api/organizations/branches/{branch}/onboarding/ccsid
     *
     * Requires OTP from ZATCA portal (valid for 1 hour).
     * This is a LIVE process - OTP expires quickly.
     */
    public function requestCcsid(Request $request, string $branchId): JsonResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $organization = $this->tenant->getOrganization();
        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        if (! $organization->hasCompleteZatcaProfile()) {
            return ApiResponse::error(
                'Organization profile incomplete. Ensure VAT number and address are set.',
                422
            );
        }

        // Check if already has CCSID
        if ($this->branchService->getCredentials($branch, 'ccsid')) {
            return ApiResponse::error(
                'Branch already has CCSID. Proceed to compliance check or request new OTP to re-onboard.',
                422
            );
        }

        $csrData = $branch->getCsrData();

        try {
            // This is a LIVE call to ZATCA - OTP must be valid
            $result = $this->onboarding->requestComplianceCsid($csrData, $request->otp);

            // Store credentials for this branch
            $this->branchService->storeCredentials($branch, 'ccsid', $result);

            // Update branch status
            $branch->update(['onboarding_status' => Branch::STATUS_CSR_GENERATED]);

            return ApiResponse::success([
                'ccsid' => substr($result['ccsid'], 0, 50).'...',
                'request_id' => $result['requestId'],
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'message' => 'Compliance CSID obtained. Proceed with compliance checks.',
                'next_step' => 'POST /api/organizations/branches/'.$branch->id.'/onboarding/compliance-check',
            ]);

        } catch (\Exception $e) {
            return ApiResponse::error('Failed to obtain CCSID: '.$e->getMessage(), 422);
        }
    }

    /**
     * Step 2: Run compliance checks for branch.
     *
     * POST /api/organizations/branches/{branch}/onboarding/compliance-check
     *
     * Submits 6 test invoices to ZATCA to verify compliance.
     */
    public function runComplianceCheck(Request $request, string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $credentials = $this->branchService->getCredentials($branch, 'ccsid');

        if (! $credentials) {
            return ApiResponse::error('No CCSID found. Complete step 1 first.', 422);
        }

        // Generate test invoices for this branch
        $testInvoices = $this->generateTestInvoices($organization, $branch);

        try {
            $result = $this->onboarding->runComplianceChecks(
                $credentials['ccsid'],
                $credentials['secret'],
                $testInvoices
            );

            if ($result['passed']) {
                $branch->update(['onboarding_status' => Branch::STATUS_COMPLIANCE_PASSED]);
            }

            return ApiResponse::success([
                'passed' => $result['passed'],
                'results' => $result['results'],
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'message' => $result['passed']
                    ? 'Compliance checks passed. Proceed to request PCSID.'
                    : 'Compliance checks failed. Fix errors and retry.',
                'next_step' => $result['passed']
                    ? 'POST /api/organizations/branches/'.$branch->id.'/onboarding/pcsid'
                    : 'Fix errors and retry compliance check',
            ]);

        } catch (\Exception $e) {
            return ApiResponse::error('Compliance check failed: '.$e->getMessage(), 422);
        }
    }

    /**
     * Step 3: Request Production CSID for branch.
     *
     * POST /api/organizations/branches/{branch}/onboarding/pcsid
     */
    public function requestPcsid(Request $request, string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $credentials = $this->branchService->getCredentials($branch, 'ccsid');

        if (! $credentials) {
            return ApiResponse::error('No CCSID found. Complete steps 1-2 first.', 422);
        }

        try {
            $result = $this->onboarding->requestProductionCsid(
                $credentials['ccsid'],
                $credentials['secret'],
                $credentials['requestId']
            );

            // Store production credentials
            $this->branchService->storeCredentials($branch, 'pcsid', array_merge($result, [
                'privateKey' => $credentials['privateKey'],
            ]));

            // Parse certificate expiry
            $certificateExpiry = null;
            if (isset($result['pcsid'])) {
                $certificateExpiry = $this->parseCertificateExpiry($result['pcsid']);
            }

            // Mark branch as active
            $branch->markAsActive($certificateExpiry);

            return ApiResponse::success([
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'onboarded' => true,
                'certificate_expires_at' => $certificateExpiry?->toIso8601String(),
                'message' => 'Production CSID obtained. Branch is now ZATCA compliant and ready for invoicing.',
            ]);

        } catch (\Exception $e) {
            return ApiResponse::error('Failed to obtain PCSID: '.$e->getMessage(), 422);
        }
    }

    /**
     * Re-onboard branch with new OTP.
     *
     * POST /api/organizations/branches/{branch}/onboarding/reset
     *
     * Use this when certificate expires or needs renewal.
     */
    public function resetOnboarding(Request $request, string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        // Delete existing credentials
        $this->branchService->deleteCredentials($branch);

        // Reset status
        $branch->update([
            'onboarding_status' => Branch::STATUS_PENDING,
            'certificate_expires_at' => null,
        ]);

        return ApiResponse::success([
            'branch_id' => $branch->id,
            'message' => 'Onboarding reset. Obtain new OTP from ZATCA Fatoora Portal to start fresh.',
            'next_step' => 'POST /api/organizations/branches/'.$branch->id.'/onboarding/ccsid',
        ]);
    }

    /**
     * Generate test invoices for compliance check.
     */
    private function generateTestInvoices($organization, Branch $branch): array
    {
        $sellerAddress = $branch->getAddressData();
        $buyerAddress = new AddressData(
            street: 'Test Street',
            city: 'Riyadh',
            postalCode: '12345',
            district: 'Test District',
            buildingNumber: '1234',
            countryCode: 'SA',
        );

        $invoices = [];
        $icv = 0;
        $previousHash = base64_encode(hash('sha256', '0', true));

        $invoiceTypes = [
            ['key' => 'standard_invoice', 'typeCode' => '388', 'subtype' => '01', 'isCredit' => false, 'isDebit' => false],
            ['key' => 'standard_credit_note', 'typeCode' => '381', 'subtype' => '01', 'isCredit' => true, 'isDebit' => false],
            ['key' => 'standard_debit_note', 'typeCode' => '383', 'subtype' => '01', 'isCredit' => false, 'isDebit' => true],
            ['key' => 'simplified_invoice', 'typeCode' => '388', 'subtype' => '02', 'isCredit' => false, 'isDebit' => false],
            ['key' => 'simplified_credit_note', 'typeCode' => '381', 'subtype' => '02', 'isCredit' => true, 'isDebit' => false],
            ['key' => 'simplified_debit_note', 'typeCode' => '383', 'subtype' => '02', 'isCredit' => false, 'isDebit' => true],
        ];

        foreach ($invoiceTypes as $type) {
            $icv++;
            $isStandard = $type['subtype'] === '01';

            $billingReferenceId = null;
            $creditDebitReason = null;
            if ($type['isCredit'] || $type['isDebit']) {
                $billingReferenceId = 'INV-REF-001';
                $creditDebitReason = $type['isCredit'] ? 'Return of goods' : 'Price adjustment';
            }

            $invoiceData = new InvoiceXmlData(
                uuid: Str::uuid()->toString(),
                invoiceNumber: 'TEST-'.$branch->id.'-'.strtoupper(substr($type['key'], 0, 3)).'-'.$icv,
                icv: $icv,
                issueDate: now()->format('Y-m-d'),
                issueTime: now()->format('H:i:s'),
                invoiceTypeCode: $type['typeCode'],
                invoiceSubtype: $type['subtype'],
                currency: 'SAR',
                sellerName: $organization->name,
                sellerVatNumber: $organization->vat_number ?? '',
                sellerAddress: $sellerAddress,
                buyerName: $isStandard ? 'Test Buyer Company' : 'Cash Customer',
                subtotal: 100.00,
                taxAmount: 15.00,
                total: 115.00,
                lines: [
                    [
                        'description' => 'Test Product',
                        'quantity' => 1.0,
                        'unitPrice' => 100.00,
                        'taxRate' => 15.0,
                        'taxAmount' => 15.00,
                        'lineTotal' => 100.00,
                        'taxCategory' => 'S',
                        'unitCode' => 'PCE',
                    ],
                ],
                sellerCrNumber: $organization->cr_number,
                buyerVatNumber: $isStandard ? '300000000000003' : null,
                buyerAddress: $isStandard ? $buyerAddress : null,
                paymentMeansCode: '10',
                previousInvoiceHash: $previousHash,
                billingReferenceId: $billingReferenceId,
                creditDebitReason: $creditDebitReason,
            );

            $xml = $this->xmlBuilder->build($invoiceData);
            $invoices[$type['key']] = $xml;

            $previousHash = base64_encode(hash('sha256', $xml, true));
        }

        return $invoices;
    }

    /**
     * Parse certificate expiry from PEM.
     */
    private function parseCertificateExpiry(string $certificate): ?\DateTime
    {
        try {
            $cert = openssl_x509_read($certificate);
            if (! $cert) {
                return null;
            }

            $certInfo = openssl_x509_parse($cert);
            if (! $certInfo || ! isset($certInfo['validTo_time_t'])) {
                return null;
            }

            return (new \DateTime)->setTimestamp($certInfo['validTo_time_t']);
        } catch (\Exception $e) {
            return null;
        }
    }
}

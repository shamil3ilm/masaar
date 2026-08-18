<?php

namespace App\Domains\Compliance\Fatoora\Http\Controllers;

use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Fatoora\Services\CsidOnboarding;
use App\Domains\Compliance\Fatoora\Services\XmlBuilder;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ZATCA Onboarding API controller.
 *
 * Handles the ZATCA onboarding flow:
 * 1. Generate CSR
 * 2. Get Compliance CSID (CCSID)
 * 3. Run compliance checks
 * 4. Get Production CSID (PCSID)
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly CsidOnboarding $onboarding,
        private readonly XmlBuilder $xmlBuilder,
    ) {}

    /**
     * Step 1: Request Compliance CSID.
     *
     * POST /api/compliance/onboarding/ccsid
     *
     * Requires OTP from ZATCA portal (valid for 1 hour).
     */
    public function requestCcsid(Request $request): JsonResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6'],
            'common_name' => ['required', 'string', 'max:255'],
            'serial_number' => ['required', 'string', 'max:50'],
            'organization_unit' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
        ]);

        $organization = $this->tenant->getOrganization();

        if (! $organization->hasCompleteZatcaProfile()) {
            return ApiResponse::error('Organization profile incomplete. Ensure VAT number and address are set.', 422);
        }

        $csrData = new CsrData(
            organizationName: $organization->name,
            organizationUnit: $request->organization_unit ?? 'Main Branch',
            commonName: $request->common_name,
            vatNumber: $organization->vat_number,
            serialNumber: $request->serial_number,
            location: $request->location ?? $organization->city ?? '',
            industry: $request->industry ?? 'General',
        );

        try {
            $result = $this->onboarding->requestComplianceCsid($csrData, $request->otp);

            // Store credentials securely
            $this->storeCredentials($organization->id, 'ccsid', $result);

            return ApiResponse::success([
                'ccsid' => substr($result['ccsid'], 0, 50).'...', // Truncate for display
                'request_id' => $result['requestId'],
                'message' => 'Compliance CSID obtained. Proceed with compliance checks.',
            ]);

        } catch (\Exception $e) {
            return ApiResponse::error('Failed to obtain CCSID: '.$e->getMessage(), 422);
        }
    }

    /**
     * Step 2: Run compliance checks.
     *
     * POST /api/compliance/onboarding/compliance-check
     */
    public function runComplianceCheck(Request $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $credentials = $this->getCredentials($organization->id, 'ccsid');

        if (! $credentials) {
            return ApiResponse::error('No CCSID found. Complete step 1 first.', 422);
        }

        // Generate test invoices for compliance check
        $testInvoices = $this->generateTestInvoices($organization);

        try {
            $result = $this->onboarding->runComplianceChecks(
                $credentials['ccsid'],
                $credentials['secret'],
                $testInvoices
            );

            return ApiResponse::success([
                'passed' => $result['passed'],
                'results' => $result['results'],
                'message' => $result['passed']
                    ? 'Compliance checks passed. Proceed to request PCSID.'
                    : 'Compliance checks failed. Fix errors and retry.',
            ]);

        } catch (\Exception $e) {
            return ApiResponse::error('Compliance check failed: '.$e->getMessage(), 422);
        }
    }

    /**
     * Step 3: Request Production CSID.
     *
     * POST /api/compliance/onboarding/pcsid
     */
    public function requestPcsid(Request $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $credentials = $this->getCredentials($organization->id, 'ccsid');

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
            $this->storeCredentials($organization->id, 'pcsid', array_merge($result, [
                'privateKey' => $credentials['privateKey'],
            ]));

            // Update organization status
            $organization->update([
                'compliance_profile' => array_merge($organization->compliance_profile ?? [], [
                    'zatca_onboarded' => true,
                    'onboarded_at' => now()->toISOString(),
                ]),
            ]);

            return ApiResponse::success([
                'message' => 'Production CSID obtained. Organization is now ZATCA compliant.',
                'onboarded' => true,
            ]);

        } catch (\Exception $e) {
            return ApiResponse::error('Failed to obtain PCSID: '.$e->getMessage(), 422);
        }
    }

    /**
     * Get onboarding status.
     *
     * GET /api/compliance/onboarding/status
     */
    public function status(): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $hasCcsid = $this->getCredentials($organization->id, 'ccsid') !== null;
        $hasPcsid = $this->getCredentials($organization->id, 'pcsid') !== null;

        return ApiResponse::success([
            'profile_complete' => $organization->hasCompleteZatcaProfile(),
            'vat_number_valid' => $organization->isValidVatNumber(),
            'has_ccsid' => $hasCcsid,
            'has_pcsid' => $hasPcsid,
            'is_onboarded' => $organization->compliance_profile['zatca_onboarded'] ?? false,
            'steps' => [
                'step_1' => $hasCcsid ? 'completed' : 'pending',
                'step_2' => $hasCcsid && ! $hasPcsid ? 'in_progress' : ($hasPcsid ? 'completed' : 'pending'),
                'step_3' => $hasPcsid ? 'completed' : 'pending',
            ],
        ]);
    }

    /**
     * Store credentials securely.
     */
    private function storeCredentials(string $organizationId, string $type, array $data): void
    {
        $path = "zatca/{$organizationId}/{$type}.json";
        Storage::disk('local')->put($path, encrypt(json_encode($data)));
    }

    /**
     * Get stored credentials.
     */
    private function getCredentials(string $organizationId, string $type): ?array
    {
        $path = "zatca/{$organizationId}/{$type}.json";

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $content = Storage::disk('local')->get($path);

        return json_decode(decrypt($content), true);
    }

    /**
     * Generate test invoices for ZATCA compliance check.
     *
     * Creates all 6 required invoice types per ZATCA specifications:
     * 1. Standard Invoice (388, 01)
     * 2. Standard Credit Note (381, 01)
     * 3. Standard Debit Note (383, 01)
     * 4. Simplified Invoice (388, 02)
     * 5. Simplified Credit Note (381, 02)
     * 6. Simplified Debit Note (383, 02)
     *
     * @return array<string, string> Invoice type => XML
     */
    private function generateTestInvoices(Organization $organization): array
    {
        $sellerAddress = $organization->getAddressData();
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
        $previousHash = base64_encode(hash('sha256', '0', true)); // Initial PIH

        // Define the 6 required invoice types
        $invoiceTypes = [
            ['key' => 'standard_invoice', 'name' => 'Standard Invoice', 'typeCode' => '388', 'subtype' => '01', 'isCredit' => false, 'isDebit' => false],
            ['key' => 'standard_credit_note', 'name' => 'Standard Credit Note', 'typeCode' => '381', 'subtype' => '01', 'isCredit' => true, 'isDebit' => false],
            ['key' => 'standard_debit_note', 'name' => 'Standard Debit Note', 'typeCode' => '383', 'subtype' => '01', 'isCredit' => false, 'isDebit' => true],
            ['key' => 'simplified_invoice', 'name' => 'Simplified Invoice', 'typeCode' => '388', 'subtype' => '02', 'isCredit' => false, 'isDebit' => false],
            ['key' => 'simplified_credit_note', 'name' => 'Simplified Credit Note', 'typeCode' => '381', 'subtype' => '02', 'isCredit' => true, 'isDebit' => false],
            ['key' => 'simplified_debit_note', 'name' => 'Simplified Debit Note', 'typeCode' => '383', 'subtype' => '02', 'isCredit' => false, 'isDebit' => true],
        ];

        foreach ($invoiceTypes as $type) {
            $icv++;
            $isStandard = $type['subtype'] === '01';

            // Credit/debit notes require billing reference and reason (BR-KSA-17)
            $billingReferenceId = null;
            $creditDebitReason = null;
            if ($type['isCredit'] || $type['isDebit']) {
                $billingReferenceId = 'INV-REF-001';
                $creditDebitReason = $type['isCredit'] ? 'Return of goods' : 'Price adjustment';
            }

            $invoiceData = new InvoiceXmlData(
                uuid: Str::uuid()->toString(),
                invoiceNumber: 'TEST-'.strtoupper(substr($type['key'], 0, 3)).'-'.time().'-'.$icv,
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
                        'lineTotal' => 100.00, // Net amount (pre-tax) per UBL 2.1
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

            // Update PIH for next invoice (simplified hash calculation for test)
            $previousHash = base64_encode(hash('sha256', $xml, true));
        }

        return $invoices;
    }
}

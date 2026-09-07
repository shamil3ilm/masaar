<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EncodesCsr;
use App\Console\Commands\Concerns\FindsOpenSsl;
use App\Console\Commands\Concerns\WritesSecrets;
use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Fatoora\DTOs\QrCodeData;
use App\Domains\Compliance\Fatoora\Services\CertificateService;
use App\Domains\Compliance\Fatoora\Services\EcdsaSigner;
use App\Domains\Compliance\Fatoora\Services\InvoiceHasher;
use App\Domains\Compliance\Fatoora\Services\QrCodeGenerator;
use App\Domains\Compliance\Fatoora\Services\TlvEncoder;
use App\Domains\Compliance\Fatoora\Services\XadesSigner;
use App\Domains\Compliance\Fatoora\Services\XmlBuilder;
use App\Support\Xml;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ZATCA EGS Onboarding Command
 *
 * Handles the complete onboarding flow for Simulation/Production environments:
 * 1. Request Compliance CSID (CCSID) using OTP
 * 2. Generate and submit 6 compliance invoices
 * 3. Request Production CSID (PCSID)
 *
 * IMPORTANT: In Simulation/Production, you MUST submit all 6 invoice types
 * for compliance verification BEFORE you can obtain the PCSID.
 *
 * Usage:
 *   php artisan fatoora:onboard --step=ccsid --otp=<your-otp>
 *   php artisan fatoora:onboard --step=compliance
 *   php artisan fatoora:onboard --step=pcsid
 *   php artisan fatoora:onboard --step=full --otp=<your-otp>
 */
class FatooraOnboarding extends Command
{
    use EncodesCsr;
    use FindsOpenSsl;
    use WritesSecrets;

    protected $signature = 'fatoora:onboard
                            {--step=info : Step to execute (info|ccsid|compliance|pcsid|full)}
                            {--otp= : One-Time Password from Fatoora Portal}
                            {--target=simulation : Target environment (sandbox|simulation|production|local)}
                            {--csr= : Path to CSR file (default: storage/app/zatca/taxpayer.csr)}
                            {--key= : Path to private key file (default: storage/app/zatca/taxpayer.key)}';

    protected $description = 'Complete ZATCA EGS onboarding with 6-invoice compliance check';

    /**
     * Local mode makes no API calls and signs against a self-signed
     * certificate; the real environments come from config, which is where the
     * rest of the platform reads them.
     */
    private const LOCAL = 'local';

    private InvoiceHasher $hasher;

    private XadesSigner $signer;

    private QrCodeGenerator $qrGenerator;

    private CertificateService $certificateService;

    private string $environment;

    private string $baseUrl;

    public function __construct()
    {
        parent::__construct();
        $this->hasher = new InvoiceHasher;
        $ecdsaSigner = new EcdsaSigner;
        $this->certificateService = new CertificateService;
        $this->signer = new XadesSigner($ecdsaSigner, $this->certificateService);
        $this->qrGenerator = new QrCodeGenerator(new TlvEncoder);
    }

    /**
     * A client pointed at ZATCA, verifying TLS unless told otherwise.
     *
     * The three calls below each opened with Http::withoutVerifying(), which
     * is not conditional on anything. So --target=production reached the live
     * authority with certificate verification off, carrying the OTP up and
     * bringing the production CSID and its secret back — the two things on
     * this path worth intercepting.
     *
     * FatooraClient has always gated this on fatoora.ssl_verify, which
     * defaults to true. This reads the same setting, so turning verification
     * off is a deliberate act in one place rather than a property of the
     * console.
     */
    private function zatca(): PendingRequest
    {
        $client = Http::timeout(60)->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Accept-Version' => 'V2',
            'Accept-Language' => 'en',
        ]);

        if (! config('fatoora.ssl_verify', true)) {
            $client->withoutVerifying();
        }

        return $client;
    }

    public function handle(): int
    {
        $this->environment = $this->option('target');
        $this->baseUrl = $this->environment === self::LOCAL
            ? self::LOCAL
            : (string) config(
                "fatoora.endpoints.{$this->environment}",
                config('fatoora.endpoints.simulation')
            );

        $step = $this->option('step');

        return match ($step) {
            'info' => $this->showInfo(),
            'ccsid' => $this->requestComplianceCsid(),
            'compliance' => $this->runComplianceCheck(),
            'pcsid' => $this->requestProductionCsid(),
            'full' => $this->runFullOnboarding(),
            default => $this->showInfo(),
        };
    }

    private function showInfo(): int
    {
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║           ZATCA EGS Onboarding Flow                          ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->comment('The onboarding process has 3 steps:');
        $this->newLine();

        $this->table(['Step', 'Description', 'Command'], [
            ['1. CCSID', 'Request Compliance CSID using OTP', 'php artisan fatoora:onboard --step=ccsid --otp=<otp>'],
            ['2. Compliance', 'Submit 6 invoice types for validation', 'php artisan fatoora:onboard --step=compliance'],
            ['3. PCSID', 'Request Production CSID', 'php artisan fatoora:onboard --step=pcsid'],
        ]);
        $this->newLine();

        $this->warn('⚠️  IMPORTANT: Simulation/Production requires all 6 invoices!');
        $this->newLine();

        $this->info('📋 Required Invoice Types (for compliance check):');
        $this->table(['ICV', 'Type', 'Code', 'SubType'], [
            ['1', 'Standard Invoice', '388', '0100000'],
            ['2', 'Standard Credit Note', '381', '0100000'],
            ['3', 'Standard Debit Note', '383', '0100000'],
            ['4', 'Simplified Invoice', '388', '0200000'],
            ['5', 'Simplified Credit Note', '381', '0200000'],
            ['6', 'Simplified Debit Note', '383', '0200000'],
        ]);
        $this->newLine();

        $this->info('📌 ICV/PIH Rules (per ZATCA specification):');
        $this->line('  • ICV is SEQUENTIAL across ALL invoice types (one counter per CSID)');
        $this->line('  • PIH chain links ALL documents regardless of type');
        $this->line('  • One certificate per company is sufficient');
        $this->line('  • Multiple branches can share one CSID (central server)');
        $this->line('  • For branch-specific invoices, use "Other Seller ID" field');
        $this->newLine();

        $this->info('🔗 How to get OTP:');
        $this->line('  1. Go to https://fatoora.zatca.gov.sa/');
        $this->line('  2. Login with your TIN credentials');
        $this->line('  3. Click "Fatoora Simulation Portal" (top right) for testing');
        $this->line('  4. Go to "Onboard New Solution Unit/Device"');
        $this->line('  5. Generate OTP (valid for 1 hour)');
        $this->newLine();

        $this->info('Quick Start (full onboarding):');
        $this->line('  php artisan fatoora:onboard --step=full --otp=<your-otp> --target=simulation');

        return Command::SUCCESS;
    }

    private function requestComplianceCsid(): int
    {
        // Handle local test mode
        if ($this->environment === 'local') {
            return $this->generateLocalCcsid();
        }

        $otp = $this->option('otp');
        if (! $otp) {
            $this->error('OTP is required. Use --otp=<your-otp>');
            $this->line('Get OTP from: https://fatoora.zatca.gov.sa/');
            $this->newLine();
            $this->info('TIP: Use --target=local for local testing without OTP');

            return Command::FAILURE;
        }

        $csrPath = $this->option('csr') ?? storage_path('app/zatca/taxpayer.csr');
        if (! file_exists($csrPath)) {
            $this->error("CSR not found at: {$csrPath}");
            $this->line('Generate CSR first: php artisan fatoora:generate-csr');

            return Command::FAILURE;
        }

        $this->info("Requesting Compliance CSID from ZATCA {$this->environment}...");
        $this->line("Endpoint: {$this->baseUrl}/compliance");
        $this->newLine();

        $csrContent = file_get_contents($csrPath);

        $csrBase64 = $this->encodeCsrForZatca($csrContent);

        try {
            $response = $this->zatca()
                ->withHeaders(['OTP' => $otp])
                ->post($this->baseUrl.'/compliance', [
                    'csr' => $csrBase64,
                ]);

            $this->line("Status: {$response->status()}");

            if ($response->successful()) {
                $data = $response->json();

                $this->info('✓ Compliance CSID obtained successfully!');
                $this->newLine();

                // Save credentials
                $this->saveCcsidCredentials($data);

                $this->table(['Field', 'Value'], [
                    ['Request ID', $data['requestID'] ?? 'N/A'],
                    ['Disposition', $data['dispositionMessage'] ?? 'N/A'],
                    ['Token Type', $data['tokenType'] ?? 'N/A'],
                ]);
                $this->newLine();

                $this->info('NEXT: Run compliance check with 6 invoices:');
                $this->line("  php artisan fatoora:onboard --step=compliance --target={$this->environment}");

                return Command::SUCCESS;
            }

            $this->error('Failed to get CCSID');
            $this->line('Response: '.$response->body());

            if ($response->status() === 400) {
                $errors = $response->json('errors') ?? [];
                foreach ($errors as $error) {
                    $this->line("  - {$error['code']}: {$error['message']}");
                }
            }

            return Command::FAILURE;

        } catch (\Exception $e) {
            $this->error('Request failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Generate a local self-signed certificate for testing without ZATCA API.
     */
    private function generateLocalCcsid(): int
    {
        $this->info('Generating LOCAL test CCSID (self-signed certificate)...');
        $this->warn('NOTE: This is for local testing only. Not valid for ZATCA submission.');
        $this->newLine();

        $csrPath = $this->option('csr') ?? storage_path('app/zatca/taxpayer.csr');
        $keyPath = $this->option('key') ?? storage_path('app/zatca/taxpayer.key');

        if (! file_exists($csrPath)) {
            $this->error("CSR not found at: {$csrPath}");
            $this->line('Generate CSR first: php artisan fatoora:generate-csr');

            return Command::FAILURE;
        }

        if (! file_exists($keyPath)) {
            $this->error("Private key not found at: {$keyPath}");

            return Command::FAILURE;
        }

        try {
            // Generate self-signed certificate from CSR
            $certPath = storage_path('app/zatca/local_cert.pem');

            // Find OpenSSL executable
            $opensslCmd = $this->findOpenSsl();
            if (! $opensslCmd) {
                $this->error('OpenSSL not found. Please install OpenSSL or add it to PATH.');
                $this->line('Common locations:');
                $this->line('  - C:\\laragon\\bin\\git\\usr\\bin\\openssl.exe');
                $this->line('  - C:\\Program Files\\Git\\usr\\bin\\openssl.exe');

                return Command::FAILURE;
            }

            // Use OpenSSL to create self-signed cert
            $cmd = sprintf(
                '"%s" x509 -req -in "%s" -signkey "%s" -out "%s" -days 365 -sha256 2>&1',
                $opensslCmd,
                $csrPath,
                $keyPath,
                $certPath
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0 || ! file_exists($certPath)) {
                $this->error('Failed to generate self-signed certificate');
                $this->line(implode("\n", $output));

                return Command::FAILURE;
            }

            $this->info('✓ Self-signed certificate generated');

            // Create mock CCSID credentials
            $privateKey = file_get_contents($keyPath);
            $certificate = file_get_contents($certPath);

            $data = [
                'requestID' => 'LOCAL-'.time(),
                'dispositionMessage' => 'LOCAL TEST MODE',
                'binarySecurityToken' => base64_encode($certificate),
                'secret' => base64_encode('local-test-secret-'.bin2hex(random_bytes(16))),
                'tokenType' => 'LOCAL',
            ];

            // Save credentials with private key
            $data['privateKey'] = $privateKey;
            $data['certificate'] = $certificate;

            $this->saveCcsidCredentials($data);

            $this->table(['Field', 'Value'], [
                ['Request ID', $data['requestID']],
                ['Mode', 'LOCAL TEST'],
                ['Certificate', 'Self-signed (365 days)'],
            ]);
            $this->newLine();

            $this->info('✓ Local CCSID credentials saved!');
            $this->newLine();

            $this->info('NEXT: Run compliance check (local validation):');
            $this->line('  php artisan fatoora:onboard --step=compliance --target=local');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to generate local CCSID: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Generate local PCSID for testing (mock production credentials).
     */
    private function generateLocalPcsid(): int
    {
        $this->info('Generating LOCAL test PCSID (mock production credentials)...');
        $this->warn('NOTE: This is for local testing only. Not valid for ZATCA submission.');
        $this->newLine();

        $ccsid = $this->loadCcsidCredentials();
        if (! $ccsid) {
            $this->error('CCSID credentials not found. Run --step=ccsid first with --target=local.');

            return Command::FAILURE;
        }

        // In local mode, PCSID is essentially the same as CCSID (same self-signed cert)
        $data = [
            'requestID' => 'PCSID-LOCAL-'.time(),
            'dispositionMessage' => 'LOCAL PRODUCTION TEST MODE',
            'binarySecurityToken' => $ccsid['token'],
            'secret' => base64_encode('local-prod-secret-'.bin2hex(random_bytes(16))),
            'tokenType' => 'LOCAL-PRODUCTION',
        ];

        $this->savePcsidCredentials($data);

        $this->table(['Field', 'Value'], [
            ['Request ID', $data['requestID']],
            ['Mode', 'LOCAL PRODUCTION TEST'],
            ['Certificate', 'Same as CCSID (self-signed)'],
        ]);
        $this->newLine();

        $this->info('🎉 Local Onboarding Complete!');
        $this->line('Your LOCAL test EGS device is ready for testing.');
        $this->newLine();
        $this->line('Production credentials saved to: storage/app/zatca/pcsid_*.txt');
        $this->newLine();
        $this->warn('To test with real ZATCA servers, you need a valid OTP from Fatoora Portal.');

        return Command::SUCCESS;
    }

    private function runComplianceCheck(): int
    {
        $ccsid = $this->loadCcsidCredentials();
        if (! $ccsid) {
            $this->error('CCSID credentials not found. Run --step=ccsid first.');

            return Command::FAILURE;
        }

        // Check if we have signing credentials
        $canSign = ! empty($ccsid['certificate']) && ! empty($ccsid['privateKey']);
        if (! $canSign) {
            $this->warn('Certificate or private key not found. Invoices will be submitted unsigned.');
            $this->line('For proper compliance, provide --key option when running --step=ccsid');
            $this->newLine();
        } else {
            $this->info('Signing credentials loaded successfully.');
        }

        $isLocalMode = $this->environment === 'local';
        if ($isLocalMode) {
            $this->info('Running LOCAL 6-Invoice Compliance Check...');
            $this->warn('NOTE: Local mode validates XML structure only (no signing/cryptography).');
            $this->line('      For full validation with signing, use --target=sandbox with a valid OTP.');
        } else {
            $this->info("Running 6-Invoice Compliance Check on {$this->environment}...");
        }
        $this->info('Note: ICV is sequential across ALL types (1-6), PIH chains all documents');
        $this->newLine();

        // Define the 6 required invoice types
        // IMPORTANT: ICV counter is SHARED across all types (per ZATCA specification)
        // Each document gets the next ICV in sequence regardless of type
        $invoiceTypes = [
            ['name' => 'Standard Invoice', 'typeCode' => '388', 'subtype' => '01', 'isCredit' => false, 'isDebit' => false],
            ['name' => 'Standard Credit Note', 'typeCode' => '381', 'subtype' => '01', 'isCredit' => true, 'isDebit' => false],
            ['name' => 'Standard Debit Note', 'typeCode' => '383', 'subtype' => '01', 'isCredit' => false, 'isDebit' => true],
            ['name' => 'Simplified Invoice', 'typeCode' => '388', 'subtype' => '02', 'isCredit' => false, 'isDebit' => false],
            ['name' => 'Simplified Credit Note', 'typeCode' => '381', 'subtype' => '02', 'isCredit' => true, 'isDebit' => false],
            ['name' => 'Simplified Debit Note', 'typeCode' => '383', 'subtype' => '02', 'isCredit' => false, 'isDebit' => true],
        ];

        $results = [];
        $allPassed = true;
        $previousHash = $this->getDefaultPih();

        foreach ($invoiceTypes as $index => $type) {
            $num = $index + 1;
            $this->line("[{$num}/6] Submitting {$type['name']}...");

            try {
                // Generate invoice XML
                $invoiceData = $this->createComplianceInvoice($type, $index + 1, $previousHash);
                $builder = new XmlBuilder;
                $xml = $builder->build($invoiceData);

                // Sign invoice if credentials are available (skip in local mode for structure validation)
                if ($canSign && ! $isLocalMode) {
                    $xml = $this->signer->sign($xml, $ccsid['privateKey'], $ccsid['certificate']);

                    // Generate and inject QR code for signed invoice
                    $xml = $this->injectQrCode($xml, $invoiceData, $ccsid['certificate']);
                }

                // Calculate hash from final XML (signed or unsigned)
                // Note: hasher->hash() already returns base64-encoded hash
                $invoiceHash = $this->hasher->hash($xml);

                $previousHash = $invoiceHash;

                // Submit to compliance API or validate locally
                if ($isLocalMode) {
                    $result = $this->validateInvoiceLocally($xml, $invoiceHash, $invoiceData->uuid);
                } else {
                    $result = $this->submitComplianceInvoice($xml, $invoiceHash, $invoiceData->uuid, $ccsid);
                }

                $status = $result['success'] ? '✓ PASSED' : '✗ FAILED';
                if (! $result['success']) {
                    $allPassed = false;
                }

                $results[] = [
                    'Invoice' => $type['name'],
                    'Status' => $status,
                    'Message' => $result['message'] ?? '',
                ];
            } catch (\Exception $e) {
                $allPassed = false;
                $results[] = [
                    'Invoice' => $type['name'],
                    'Status' => '✗ ERROR',
                    'Message' => $e->getMessage(),
                ];
            }

            // Small delay between requests
            usleep(500000);
        }

        $this->newLine();
        $this->table(['Invoice', 'Status', 'Message'], $results);
        $this->newLine();

        if ($allPassed) {
            $this->info('All 6 invoices passed compliance check!');
            $this->newLine();
            $this->info('NEXT: Request Production CSID:');
            $this->line("  php artisan fatoora:onboard --step=pcsid --target={$this->environment}");

            return Command::SUCCESS;
        }

        $this->error('Some invoices failed compliance check. Review errors and retry.');

        return Command::FAILURE;
    }

    private function requestProductionCsid(): int
    {
        // Handle local test mode
        if ($this->environment === 'local') {
            return $this->generateLocalPcsid();
        }

        $ccsid = $this->loadCcsidCredentials();
        if (! $ccsid) {
            $this->error('CCSID credentials not found. Run --step=ccsid first.');

            return Command::FAILURE;
        }

        $this->info("Requesting Production CSID from ZATCA {$this->environment}...");
        $this->line("Endpoint: {$this->baseUrl}/production/csids");
        $this->newLine();

        try {
            $response = $this->zatca()
                ->withBasicAuth($ccsid['token'], $ccsid['secret'])
                ->post($this->baseUrl.'/production/csids', [
                    'compliance_request_id' => $ccsid['requestId'],
                ]);

            $this->line("Status: {$response->status()}");

            if ($response->successful()) {
                $data = $response->json();

                $this->info('✓ Production CSID obtained successfully!');
                $this->newLine();

                // Save production credentials
                $this->savePcsidCredentials($data);

                $this->table(['Field', 'Value'], [
                    ['Request ID', $data['requestID'] ?? 'N/A'],
                    ['Disposition', $data['dispositionMessage'] ?? 'N/A'],
                    ['Token Type', $data['tokenType'] ?? 'N/A'],
                ]);
                $this->newLine();

                $this->info('🎉 Onboarding Complete!');
                $this->line('Your EGS device is now registered with ZATCA.');
                $this->newLine();
                $this->line('Production credentials saved to: storage/app/zatca/pcsid_*.txt');

                return Command::SUCCESS;
            }

            $this->error('Failed to get PCSID');
            $this->line('Response: '.$response->body());

            if ($response->status() === 400) {
                $this->warn('Common reasons for PCSID failure:');
                $this->line('  - Not all 6 invoice types submitted for compliance');
                $this->line('  - Compliance check not completed successfully');
                $this->line('  - Invalid request ID');
            }

            return Command::FAILURE;

        } catch (\Exception $e) {
            $this->error('Request failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    private function runFullOnboarding(): int
    {
        $this->info('Running Full Onboarding Flow...');
        $this->newLine();

        // Step 1: Get CCSID
        $this->info('═══ Step 1/3: Request Compliance CSID ═══');
        $result = $this->requestComplianceCsid();
        if ($result !== Command::SUCCESS) {
            return $result;
        }
        $this->newLine();

        // Step 2: Compliance check
        $this->info('═══ Step 2/3: Submit 6 Compliance Invoices ═══');
        $result = $this->runComplianceCheck();
        if ($result !== Command::SUCCESS) {
            return $result;
        }
        $this->newLine();

        // Step 3: Get PCSID
        $this->info('═══ Step 3/3: Request Production CSID ═══');

        return $this->requestProductionCsid();
    }

    private function createComplianceInvoice(array $type, int $icv, string $previousHash): InvoiceXmlData
    {
        $isStandard = $type['subtype'] === '01';
        $uuid = $this->generateUuid();
        $invoiceNumber = 'COMP-'.date('Ymd').'-'.str_pad((string) $icv, 3, '0', STR_PAD_LEFT);

        // For credit/debit notes, reference a previous invoice and add reason (BR-KSA-17)
        $billingReferenceId = null;
        $creditDebitReason = null;
        if ($type['isCredit'] || $type['isDebit']) {
            $billingReferenceId = 'INV-REF-001';
            $creditDebitReason = $type['isCredit'] ? 'Return of goods' : 'Price adjustment';
        }

        return new InvoiceXmlData(
            uuid: $uuid,
            invoiceNumber: $invoiceNumber,
            icv: $icv,
            issueDate: date('Y-m-d'),
            issueTime: date('H:i:s'),
            invoiceTypeCode: $type['typeCode'],
            invoiceSubtype: $type['subtype'],
            currency: 'SAR',
            sellerName: 'Maximum Speed Tech Supply LTD',
            sellerVatNumber: '399999999900003',
            sellerAddress: new AddressData(
                street: 'King Fahd Road',
                buildingNumber: '1234',
                plotIdentification: '5678',
                district: 'Al Olaya',
                city: 'Riyadh',
                postalCode: '12345',
                countrySubentity: 'Riyadh Region',
                countryCode: 'SA',
            ),
            buyerName: 'Test Buyer Company',
            subtotal: 100.00,
            taxAmount: 15.00,
            total: 115.00,
            lines: [
                [
                    'description' => 'Test Product',
                    'quantity' => 1,
                    'unitPrice' => 100.00,
                    'taxRate' => 15.0,
                    'taxCategory' => 'S',
                    'lineTotal' => 100.00,
                    'taxAmount' => 15.00,
                ],
            ],
            supplyDate: $isStandard ? date('Y-m-d') : null,
            sellerCrNumber: '1010010000',
            buyerVatNumber: $isStandard ? '399999999800003' : null,
            buyerAddress: $isStandard ? new AddressData(
                street: 'Prince Sultan Road',
                buildingNumber: '5678',
                district: 'Al Malaz',
                city: 'Riyadh',
                postalCode: '54321',
                countryCode: 'SA',
            ) : null,
            previousInvoiceHash: $previousHash,
            billingReferenceId: $billingReferenceId,
            creditDebitReason: $creditDebitReason,
        );
    }

    private function submitComplianceInvoice(string $xml, string $hash, string $uuid, array $ccsid): array
    {
        try {
            $response = $this->zatca()
                ->withBasicAuth($ccsid['token'], $ccsid['secret'])
                ->post($this->baseUrl.'/compliance/invoices', [
                    'invoiceHash' => $hash, // Already base64-encoded from InvoiceHasher
                    'uuid' => $uuid,
                    'invoice' => base64_encode($xml),
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $status = $data['validationResults']['status'] ?? $data['clearanceStatus'] ?? 'UNKNOWN';

                return [
                    'success' => in_array($status, ['PASS', 'PASSED', 'CLEARED', 'REPORTED']),
                    'message' => $status,
                ];
            }

            // ZATCA answers a refused document in more than one shape, and
            // reporting only the two we recognised turned every other refusal
            // into a bare "HTTP 400" - which says the request was wrong and
            // nothing about what was wrong with it.
            $errorMsg = 'HTTP '.$response->status();
            $errors = $response->json('errors')
                ?? $response->json('validationResults.errorMessages')
                ?? $response->json('validationResults.errors')
                ?? [];

            if (! empty($errors)) {
                $first = $errors[0] ?? $errors;
                $errorMsg .= ': '.(is_array($first) ? ($first['message'] ?? json_encode($first)) : (string) $first);
            } else {
                $body = trim((string) $response->body());
                $errorMsg .= ': '.($body === '' ? '(empty response)' : mb_substr($body, 0, 300));
            }

            return ['success' => false, 'message' => $errorMsg];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Validate invoice locally using basic XML validation.
     *
     * In local mode, we skip SDK validation because:
     * 1. SDK requires signed invoices with valid QR codes
     * 2. SDK checks cryptographic elements (certificate, signature, PIH)
     * 3. These elements aren't available without real ZATCA credentials
     *
     * Instead, we do structural validation to ensure XML is well-formed
     * and contains all required UBL elements.
     */
    private function validateInvoiceLocally(string $xml, string $hash, string $uuid): array
    {
        // Save XML for debugging
        $debugPath = storage_path("app/zatca/debug_{$uuid}.xml");
        file_put_contents($debugPath, $xml);

        // Basic XML structure validation
        try {
            $dom = new \DOMDocument;
            Xml::load($dom, $xml, LIBXML_NOERROR | LIBXML_NOWARNING);

            // Check for required elements
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

            $checks = [
                'UUID' => $xpath->query('//cbc:UUID')->length > 0,
                'IssueDate' => $xpath->query('//cbc:IssueDate')->length > 0,
                'InvoiceTypeCode' => $xpath->query('//cbc:InvoiceTypeCode')->length > 0,
                'Seller' => $xpath->query('//cac:AccountingSupplierParty')->length > 0,
                'TaxTotal' => $xpath->query('//cac:TaxTotal')->length > 0,
                'LegalMonetaryTotal' => $xpath->query('//cac:LegalMonetaryTotal')->length > 0,
            ];

            $missing = array_keys(array_filter($checks, fn ($v) => ! $v));

            if (empty($missing)) {
                return ['success' => true, 'message' => 'LOCAL: Structure OK'];
            }

            return ['success' => false, 'message' => 'Missing: '.implode(', ', $missing)];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'XML Error: '.$e->getMessage()];
        }
    }

    /**
     * The certificate's base64 DER, from whatever the authority sent.
     *
     * ZATCA wraps it a second time: the token decodes to the DER's own
     * base64, which is what belongs between the PEM headers. A token that is
     * already the body decodes to bytes rather than base64, so this only
     * unwraps when unwrapping produces something a PEM can hold.
     */
    private function certificateBodyFrom(string $token): string
    {
        $token = trim($token);

        if ($token === '') {
            return '';
        }

        $decoded = base64_decode($token, true);

        if ($decoded !== false && preg_match('#^[A-Za-z0-9+/=
]+$#', $decoded) === 1) {
            return trim($decoded);
        }

        return $token;
    }

    private function saveCcsidCredentials(array $data): void
    {
        $dir = $this->secretDir();

        // Save the raw token and secret
        $this->putSecret($dir.'/ccsid_token.txt', $data['binarySecurityToken'] ?? '');
        $this->putSecret($dir.'/ccsid_secret.txt', $data['secret'] ?? '');
        // ZATCA sends requestID as a JSON number, and putSecret takes a
        // string under strict_types, so onboarding died here after the
        // authority had already issued the certificate.
        $this->putSecret($dir.'/ccsid_request_id.txt', (string) ($data['requestID'] ?? ''));

        // Handle certificate - for local mode use raw cert, for API mode convert from base64
        if (! empty($data['certificate']) && str_starts_with($data['certificate'], '-----BEGIN')) {
            // Local mode: certificate is already in PEM format
            File::put($dir.'/ccsid_certificate.pem', $data['certificate']);
        } else {
            // binarySecurityToken is base64 of the certificate's base64 DER -
            // one layer more than a PEM body. Wrapping it as it arrived put
            // base64 of base64 between the headers, which openssl_x509_read
            // refuses with "X.509 Certificate cannot be retrieved", and every
            // document signed with it failed the compliance check.
            $certBase64 = $this->certificateBodyFrom((string) ($data['binarySecurityToken'] ?? ''));

            if ($certBase64 !== '') {
                $certPem = '-----BEGIN CERTIFICATE-----
'.
                    chunk_split($certBase64, 64, '
').
                    '-----END CERTIFICATE-----';
                File::put($dir.'/ccsid_certificate.pem', $certPem);
            }
        }

        // Copy private key if provided via --key option
        $keyPath = $this->option('key') ?? storage_path('app/zatca/taxpayer.key');
        if (file_exists($keyPath)) {
            $privateKey = file_get_contents($keyPath);
            $this->putSecret($dir.'/ccsid_private_key.pem', $privateKey);
            $this->line('Private key copied from: '.$keyPath);
        } else {
            $this->warn('Private key not found at: '.$keyPath);
            $this->line('You will need to provide the private key for invoice signing.');
        }

        $this->line('Credentials saved to storage/app/zatca/ccsid_*.txt');
    }

    private function loadCcsidCredentials(): ?array
    {
        $dir = storage_path('app/zatca');
        $tokenPath = $dir.'/ccsid_token.txt';
        $secretPath = $dir.'/ccsid_secret.txt';
        $requestIdPath = $dir.'/ccsid_request_id.txt';
        $certPath = $dir.'/ccsid_certificate.pem';
        $keyPath = $dir.'/ccsid_private_key.pem';

        if (! file_exists($tokenPath) || ! file_exists($secretPath)) {
            return null;
        }

        return [
            'token' => trim(file_get_contents($tokenPath)),
            'secret' => trim(file_get_contents($secretPath)),
            'requestId' => file_exists($requestIdPath) ? trim(file_get_contents($requestIdPath)) : '',
            'certificate' => file_exists($certPath) ? trim(file_get_contents($certPath)) : null,
            'privateKey' => file_exists($keyPath) ? trim(file_get_contents($keyPath)) : null,
        ];
    }

    private function savePcsidCredentials(array $data): void
    {
        $dir = $this->secretDir();
        $this->putSecret($dir.'/pcsid_token.txt', $data['binarySecurityToken'] ?? '');
        $this->putSecret($dir.'/pcsid_secret.txt', $data['secret'] ?? '');

        // A JSON number, as in the CCSID response, and putSecret takes a string
        // under strict_types.
        $this->putSecret($dir.'/pcsid_request_id.txt', (string) ($data['requestID'] ?? ''));

        // The CCSID path writes a usable certificate and this one did not, so
        // the step that finishes onboarding left nothing behind to sign with.
        $body = $this->certificateBodyFrom((string) ($data['binarySecurityToken'] ?? ''));

        if ($body !== '') {
            File::put(
                $dir.'/pcsid_certificate.pem',
                '-----BEGIN CERTIFICATE-----
'.chunk_split($body, 64, '
').'-----END CERTIFICATE-----'
            );
        }

        $this->line('Production credentials saved to storage/app/zatca/pcsid_*.txt');
    }

    private function getDefaultPih(): string
    {
        // ZATCA SDK default PIH for first invoice in chain
        return 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';
    }

    /**
     * Inject QR code into signed invoice XML.
     *
     * For Phase 2, QR code requires:
     * - Tags 1-5: Basic info (seller, VAT, timestamp, totals)
     * - Tags 6-9: Cryptographic data (hash, signature, public key, cert signature)
     */
    private function injectQrCode(string $signedXml, InvoiceXmlData $invoiceData, string $certificate): string
    {
        // Put the QR element in place before hashing, empty for now.
        //
        // Inserting it is a DOM round trip, and re-serializing changes bytes
        // the hash covers — self-closing tags, entity escapes — so a hash taken
        // before it does not describe the document that gets sent. ZATCA
        // recomputes from what it receives and answered "invoice xml hash does
        // not match with qr code invoice xml hash" for every simplified
        // document. Hashing the settled shape, with the QR excluded as the
        // specification requires, is what makes tag 6 true of the document
        // carrying it.
        $signedXml = $this->insertQrCodeIntoXml($signedXml, '');

        $invoiceHash = $this->hasher->hash($signedXml);

        // Extract signature from signed XML
        $signature = $this->signer->extractSignature($signedXml);

        // Extract public key from certificate
        $certResource = openssl_x509_read($certificate);
        if ($certResource === false) {
            throw new RuntimeException('Failed to parse certificate');
        }
        $pubKeyResource = openssl_pkey_get_public($certResource);
        $pubKeyDetails = openssl_pkey_get_details($pubKeyResource);
        $publicKeyPem = $pubKeyDetails['key'] ?? '';

        // Extract raw public key bytes (without PEM headers)
        $publicKeyDer = base64_decode(str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
            '',
            $publicKeyPem
        ));

        // Extract the certificate signature from DER-encoded certificate
        $certDer = base64_decode(str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"],
            '',
            $certificate
        ));
        // Read it out of the structure rather than taking the last 72 bytes.
        // An ECDSA signature is 70 to 72 bytes depending on whether r and s
        // need a leading zero, so a fixed slice is right sometimes and
        // silently wrong the rest of the time.
        $certSignature = $this->certificateSignature($certDer);

        // Build QR code data
        $qrData = new QrCodeData(
            sellerName: $invoiceData->sellerName,
            vatNumber: $invoiceData->sellerVatNumber,
            timestamp: $invoiceData->issueDate.'T'.$invoiceData->issueTime,
            invoiceTotal: number_format($invoiceData->total, 2, '.', ''),
            vatTotal: number_format($invoiceData->taxAmount, 2, '.', ''),
            // hash() already returns base64. Encoding it again put base64 of
            // base64 in tag 6, which is not the hash ZATCA recomputes.
            invoiceHash: $invoiceHash,
            signature: $signature ?? '',
            // Raw bytes, not base64. Tags 6 and 7 carry base64 text and these
            // two carry DER; encoding them made the QR a third longer than
            // ZATCA's, and the authority answered "ECDSA Public Key does not
            // match with qr code ECDSA public key".
            publicKey: $publicKeyDer,
            certificateSignature: $certSignature,
        );

        // Nine tags whenever the cryptographic material exists, which after
        // signing it does. This chose by document type and chose the wrong way
        // round: the stamp belongs on simplified invoices, which ZATCA verifies
        // from the QR because it never sees them before they are issued. A
        // five-tag QR on a simplified invoice is refused with "Failed to
        // validate QR code", and every one of ours was refused.
        try {
            $qrCode = $this->qrGenerator->generatePhase2($qrData);
        } catch (\Exception $e) {
            // Falling back silently produced a QR the authority rejects and
            // said nothing about why, so say it.
            $this->warn('Phase 2 QR unavailable ('.$e->getMessage().'); falling back to five tags.');
            $qrCode = $this->qrGenerator->generatePhase1($qrData);
        }

        // Inject QR code into the XML
        return $this->insertQrCodeIntoXml($signedXml, $qrCode);
    }

    /**
     * Insert QR code element into XML string.
     */
    /**
     * The signature bytes of a DER-encoded certificate.
     *
     * Certificate ::= SEQUENCE { tbsCertificate, signatureAlgorithm,
     * signatureValue BIT STRING }, so this walks to the third element and
     * drops the BIT STRING's unused-bits byte.
     */
    private function certificateSignature(string $der): string
    {
        $offset = 0;
        [, $certificate] = $this->readDer($der, $offset);

        $inner = 0;
        $this->readDer($certificate, $inner);
        $this->readDer($certificate, $inner);
        [$tag, $signature] = $this->readDer($certificate, $inner);

        if ($tag !== 0x03) {
            throw new RuntimeException('Certificate does not end in a signature BIT STRING.');
        }

        // the first byte counts unused bits and is not part of the signature
        return substr($signature, 1);
    }

    /**
     * One DER element from $buf, advancing $pos past it.
     *
     * @return array{int, string} tag and value
     */
    private function readDer(string $buf, int &$pos): array
    {
        $tag = ord($buf[$pos]);
        $length = ord($buf[$pos + 1]);
        $pos += 2;

        if ($length > 0x80) {
            $count = $length - 0x80;
            $length = 0;

            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($buf[$pos + $i]);
            }

            $pos += $count;
        }

        $value = substr($buf, $pos, $length);
        $pos += $length;

        return [$tag, $value];
    }

    private function insertQrCodeIntoXml(string $xml, string $qrCode): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        // The QR goes in after the hash has been taken, so this must not
        // reformat the document. Loading with whitespace stripped rewrote
        // every line of it, and ZATCA recomputing the hash from what it
        // received got a different answer from the one in tag 6.
        $dom->preserveWhiteSpace = true;
        Xml::load($dom, $xml);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        // Check if QR already exists
        $existingQr = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject");
        if ($existingQr->length > 0) {
            $existingQr->item(0)->nodeValue = $qrCode;
        } else {
            // Create new QR element
            $qrRef = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cac:AdditionalDocumentReference');
            $qrRef->appendChild($dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'cbc:ID', 'QR'));

            $attachment = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cac:Attachment');
            $binary = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'cbc:EmbeddedDocumentBinaryObject', $qrCode);
            $binary->setAttribute('mimeCode', 'text/plain');
            $attachment->appendChild($binary);
            $qrRef->appendChild($attachment);

            // Insert after PIH
            $pihNodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='PIH']");
            if ($pihNodes->length > 0) {
                $pihNode = $pihNodes->item(0);
                if ($pihNode->nextSibling) {
                    $pihNode->parentNode->insertBefore($qrRef, $pihNode->nextSibling);
                } else {
                    $pihNode->parentNode->appendChild($qrRef);
                }
            } else {
                $dom->documentElement->appendChild($qrRef);
            }
        }

        $dom->formatOutput = true;

        return $dom->saveXML();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

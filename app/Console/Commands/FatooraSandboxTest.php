<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EncodesCsr;
use App\Console\Commands\Concerns\FindsOpenSsl;
use App\Console\Commands\Concerns\WritesSecrets;
use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Compliance\Fatoora\Services\CsrBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\EC;

/**
 * ZATCA Integration Sandbox Testing Command
 *
 * Test your e-invoicing compliance directly with ZATCA's sandbox API
 * without needing to install Java or the ZATCA SDK locally.
 *
 * Prerequisites:
 * 1. Generate a CSR (Certificate Signing Request)
 * 2. Get OTP from ZATCA portal (for production) or use sandbox OTP
 * 3. Obtain Compliance CSID
 * 4. Submit test invoices
 *
 * Usage:
 *   php artisan fatoora:sandbox-test --step=info
 *   php artisan fatoora:sandbox-test --step=generate-csr
 *   php artisan fatoora:sandbox-test --step=compliance-csid --otp=123456
 *   php artisan fatoora:sandbox-test --step=compliance-check --invoice-hash=xxx
 */
class FatooraSandboxTest extends Command
{
    use EncodesCsr;
    use FindsOpenSsl;
    use WritesSecrets;

    protected $signature = 'fatoora:sandbox-test
                            {--step=info : Step to execute (info|generate-csr|compliance-csid|compliance-check|report)}
                            {--otp= : One-Time Password for CSID request}
                            {--csr= : CSR file path}
                            {--csid= : CSID for compliance check}
                            {--secret= : CSID secret}
                            {--invoice= : Invoice XML file path}';

    protected $description = 'Test e-invoicing compliance with ZATCA Integration Sandbox (no Java/SDK required)';

    // ZATCA Sandbox URLs
    private const SANDBOX_BASE_URL = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';

    private const SANDBOX_COMPLIANCE_CSID = '/compliance';

    private const SANDBOX_COMPLIANCE_CHECK = '/compliance/invoices';

    private const SANDBOX_PRODUCTION_CSID = '/production/csids';

    private const SANDBOX_REPORTING = '/invoices/reporting/single';

    private const SANDBOX_CLEARANCE = '/invoices/clearance/single';

    public function handle(): int
    {
        $step = $this->option('step');

        return match ($step) {
            'info' => $this->showInfo(),
            'generate-csr' => $this->generateCsr(),
            'compliance-csid' => $this->getComplianceCsid(),
            'compliance-check' => $this->runComplianceCheck(),
            'report' => $this->submitReport(),
            default => $this->showInfo(),
        };
    }

    private function showInfo(): int
    {
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║           ZATCA Integration Sandbox Guide                    ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->comment('The ZATCA Sandbox allows you to test e-invoicing compliance');
        $this->comment('without affecting production systems.');
        $this->newLine();

        $this->info('📋 ONBOARDING FLOW:');
        $this->table(['Step', 'Command', 'Description'], [
            ['1', 'generate-csr', 'Generate Certificate Signing Request (CSR)'],
            ['2', 'compliance-csid', 'Get Compliance CSID using OTP'],
            ['3', 'compliance-check', 'Submit 6 test invoices for compliance'],
            ['4', 'production-csid', 'Get Production CSID (after compliance)'],
        ]);
        $this->newLine();

        $this->info('🔗 IMPORTANT URLS:');
        $this->line('  • Sandbox Portal: https://sandbox.zatca.gov.sa/');
        $this->line('  • Developer Portal: https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/');
        $this->line('  • API Docs: https://sandbox.zatca.gov.sa/IntegrationSandbox');
        $this->line('  • Fatoora Community: https://zatca1.discourse.group/');
        $this->newLine();

        $this->info('🧪 SANDBOX API ENDPOINTS:');
        $this->table(['Endpoint', 'Purpose'], [
            [self::SANDBOX_BASE_URL.self::SANDBOX_COMPLIANCE_CSID, 'Get Compliance CSID'],
            [self::SANDBOX_BASE_URL.self::SANDBOX_COMPLIANCE_CHECK, 'Submit Compliance Invoices'],
            [self::SANDBOX_BASE_URL.self::SANDBOX_PRODUCTION_CSID, 'Get Production CSID'],
            [self::SANDBOX_BASE_URL.self::SANDBOX_REPORTING, 'Report B2C Invoices'],
            [self::SANDBOX_BASE_URL.self::SANDBOX_CLEARANCE, 'Clear B2B Invoices'],
        ]);
        $this->newLine();

        $this->info('📝 COMPLIANCE CHECK REQUIREMENTS:');
        $this->line('  You must submit 6 valid invoice documents:');
        $this->line('  1. Standard Invoice');
        $this->line('  2. Standard Credit Note');
        $this->line('  3. Standard Debit Note');
        $this->line('  4. Simplified Invoice');
        $this->line('  5. Simplified Credit Note');
        $this->line('  6. Simplified Debit Note');
        $this->newLine();

        $this->warn('⚠️  SANDBOX LIMITATIONS:');
        $this->line('  • Test CSIDs cannot be used in production');
        $this->line('  • Submitted invoices are not legally valid');
        $this->line('  • For testing purposes only');
        $this->newLine();

        $this->info('▶️  NEXT STEP:');
        $this->line('  php artisan fatoora:sandbox-test --step=generate-csr');

        return Command::SUCCESS;
    }

    /**
     * Generate a request ZATCA will accept.
     *
     * This built its own three times over — an OpenSSL path, a "simplified CSR
     * without all ZATCA extensions", and an RSA demo the authority has never
     * accepted — and each announced success for a request that is refused with
     * "Invalid Request" at the next step. CsrBuilder writes one correctly and
     * fatoora:generate-csr already uses it, so there is one implementation now
     * rather than four.
     */
    private function generateCsr(): int
    {
        $this->info('Generating CSR (Certificate Signing Request)...');
        $this->newLine();

        $data = new CsrData(
            organizationName: 'Test Company',
            organizationUnit: 'Test Branch',
            commonName: 'TST-886431145-399999999900003',
            vatNumber: '399999999900003',
            serialNumber: '1-Solution|2-1.0|3-'.bin2hex(random_bytes(14)),
            location: 'Riyadh',
            industry: 'Technology',
            invoiceTypesStandard: true,
            invoiceTypesSimplified: true,
        );

        $privateKeyPem = EC::createKey('secp256k1')->toString('PKCS8');
        $csrPem = app(CsrBuilder::class)->build($data, $privateKeyPem, CsrBuilder::TEMPLATE_SANDBOX);

        $dir = storage_path('app/zatca');

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        // csr.pem and key.pem are what the later steps of this command read;
        // taxpayer.* are what fatoora:onboard reads. Writing both means either
        // command can carry on from here, which is the point of having this
        // one at all.
        foreach (['csr.pem', 'taxpayer.csr'] as $name) {
            file_put_contents($dir.'/'.$name, $csrPem);
        }

        foreach (['key.pem', 'taxpayer.key'] as $name) {
            file_put_contents($dir.'/'.$name, $privateKeyPem);
            @chmod($dir.'/'.$name, 0600);
        }

        $this->table(['Field', 'Value'], [
            ['Common Name', $data->commonName],
            ['VAT Number', $data->vatNumber],
            ['Serial Number', $data->serialNumber],
            ['Invoice Types', $data->getInvoiceTypeCode()],
            ['Template', CsrBuilder::TEMPLATE_SANDBOX],
        ]);
        $this->newLine();

        $this->info('✓ CSR and private key written to storage/app/zatca');
        $this->newLine();
        $this->info('CSR Content (base64 for API):');
        $this->line($this->encodeCsrForZatca($csrPem));
        $this->newLine();

        $this->info('NEXT: php artisan fatoora:sandbox-test --step=compliance-csid --otp=123345');

        return Command::SUCCESS;
    }

    private function getComplianceCsid(): int
    {
        $otp = $this->option('otp');

        if (! $otp) {
            $this->error('OTP is required. Use --otp=123456');
            $this->line('For sandbox testing, the OTP is usually: 123456');

            return Command::FAILURE;
        }

        $csrPath = storage_path('app/zatca/csr.pem');
        if (! file_exists($csrPath)) {
            $this->error('CSR not found. Run --step=generate-csr first');

            return Command::FAILURE;
        }

        $csrContent = file_get_contents($csrPath);
        $csrBase64 = $this->encodeCsrForZatca($csrContent);

        $this->info('Requesting Compliance CSID from ZATCA Sandbox...');
        $this->newLine();

        $url = self::SANDBOX_BASE_URL.self::SANDBOX_COMPLIANCE_CSID;

        $this->line("POST {$url}");
        $this->line("OTP: {$otp}");
        $this->newLine();

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'OTP' => $otp,
                'Accept-Version' => 'V2',
                'Content-Type' => 'application/json',
            ])->post($url, [
                'csr' => $csrBase64,
            ]);

            $this->info('Response Status: '.$response->status());
            $this->newLine();

            if ($response->successful()) {
                $data = $response->json();

                $this->info('✓ Compliance CSID obtained!');
                $this->newLine();

                // Save CSID and secret
                $csidPath = storage_path('app/zatca/compliance_csid.txt');
                $secretPath = storage_path('app/zatca/compliance_secret.txt');

                file_put_contents($csidPath, $data['binarySecurityToken'] ?? '');
                file_put_contents($secretPath, $data['secret'] ?? '');

                $this->table(['Field', 'Value'], [
                    ['Request ID', $data['requestID'] ?? 'N/A'],
                    ['Disposition', $data['dispositionMessage'] ?? 'N/A'],
                    ['CSID', substr($data['binarySecurityToken'] ?? '', 0, 50).'...'],
                    ['Secret', substr($data['secret'] ?? '', 0, 20).'...'],
                ]);
                $this->newLine();

                $this->info('Files saved:');
                $this->line("  CSID: {$csidPath}");
                $this->line("  Secret: {$secretPath}");
                $this->newLine();

                $this->info('▶️  NEXT STEP:');
                $this->line('  php artisan fatoora:sandbox-test --step=compliance-check');
            } else {
                $this->error('Failed to get CSID');
                $this->line('Status: '.$response->status());
                $this->line('Response: '.$response->body());
                $this->newLine();

                if ($response->status() === 400) {
                    $this->warn('The CSR was rejected. Common reasons:');
                    $this->line('  • CSR missing required ZATCA extensions (serialNumber, UID, etc.)');
                    $this->line('  • Invalid OTP (try: 123456 for sandbox)');
                    $this->line('  • CSR not properly encoded');
                    $this->newLine();
                    $this->info('Solutions:');
                    $this->line('  1. Use ZATCA SDK to generate proper CSR:');
                    $this->line('     fatoora -generateCSR');
                    $this->newLine();
                    $this->line('  2. Use ZATCA Sandbox Portal to generate CSR:');
                    $this->line('     https://sandbox.zatca.gov.sa/');
                    $this->line('     Navigate to: Onboarding & CSR Generator');
                    $this->newLine();
                    $this->line('  3. For testing without proper CSR, you can:');
                    $this->line('     - Use the portal\'s built-in testing tools');
                    $this->line('     - Download sample CSR/certificates from ZATCA docs');
                }
            }
        } catch (\Exception $e) {
            $this->error('Request failed: '.$e->getMessage());
            $this->newLine();
            $this->warn('Note: The sandbox may require VPN or specific network access.');
            $this->line('Try accessing https://sandbox.zatca.gov.sa/ in your browser first.');
        }

        return Command::SUCCESS;
    }

    private function runComplianceCheck(): int
    {
        $csidPath = storage_path('app/zatca/compliance_csid.txt');
        $secretPath = storage_path('app/zatca/compliance_secret.txt');

        if (! file_exists($csidPath) || ! file_exists($secretPath)) {
            $this->error('CSID or secret not found. Run --step=compliance-csid first');

            return Command::FAILURE;
        }

        $csid = trim(file_get_contents($csidPath));
        $secret = trim(file_get_contents($secretPath));

        $this->info('Running Compliance Check with ZATCA Sandbox...');
        $this->newLine();

        // Generate the 6 required test invoices
        $testInvoices = [
            ['type' => '388', 'subtype' => '0100000', 'name' => 'Standard Invoice'],
            ['type' => '381', 'subtype' => '0100000', 'name' => 'Standard Credit Note'],
            ['type' => '383', 'subtype' => '0100000', 'name' => 'Standard Debit Note'],
            ['type' => '388', 'subtype' => '0200000', 'name' => 'Simplified Invoice'],
            ['type' => '381', 'subtype' => '0200000', 'name' => 'Simplified Credit Note'],
            ['type' => '383', 'subtype' => '0200000', 'name' => 'Simplified Debit Note'],
        ];

        $this->info('Submitting 6 compliance invoices:');
        $this->newLine();

        $results = [];
        foreach ($testInvoices as $index => $invoice) {
            $num = $index + 1;
            $this->line("[{$num}/6] {$invoice['name']}...");

            // In real implementation, generate proper XML for each type
            // For now, show the structure
            $results[] = [
                'Invoice' => $invoice['name'],
                'Type' => $invoice['type'],
                'SubType' => $invoice['subtype'],
                'Status' => 'Pending (generate XML)',
            ];
        }

        $this->newLine();
        $this->table(['Invoice', 'Type', 'SubType', 'Status'], $results);
        $this->newLine();

        $this->warn('To complete compliance check:');
        $this->line('1. Generate signed XML for each invoice type');
        $this->line('2. Submit to: '.self::SANDBOX_BASE_URL.self::SANDBOX_COMPLIANCE_CHECK);
        $this->line('3. Use Basic Auth with CSID:Secret');
        $this->newLine();

        $this->info('API Request Format:');
        $this->line('POST '.self::SANDBOX_BASE_URL.self::SANDBOX_COMPLIANCE_CHECK);
        $this->line('Headers:');
        $this->line('  Authorization: Basic '.base64_encode($csid.':'.$secret));
        $this->line('  Content-Type: application/json');
        $this->line('  Accept-Version: V2');
        $this->line('Body:');
        $this->line('  {');
        $this->line('    "invoiceHash": "<base64 hash>",');
        $this->line('    "uuid": "<invoice UUID>",');
        $this->line('    "invoice": "<base64 signed XML>"');
        $this->line('  }');

        return Command::SUCCESS;
    }

    private function submitReport(): int
    {
        $this->info('Invoice Reporting/Clearance');
        $this->newLine();

        $this->info('After passing compliance check, use these endpoints:');
        $this->newLine();

        $this->table(['Invoice Type', 'Endpoint', 'Method'], [
            ['B2B (Standard)', self::SANDBOX_BASE_URL.self::SANDBOX_CLEARANCE, 'POST'],
            ['B2C (Simplified)', self::SANDBOX_BASE_URL.self::SANDBOX_REPORTING, 'POST'],
        ]);

        return Command::SUCCESS;
    }

    private function getOpenSslConfig(array $config): string
    {
        // ZATCA CSR requires specific OIDs and format
        // Note: Full ZATCA extensions require the official SDK or portal
        $configContent = <<<EOT
# ZATCA CSR Configuration
oid_section = zatca_oids

[zatca_oids]
certificateTemplateName = 1.3.6.1.4.1.311.20.2

[req]
default_bits = 2048
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no
utf8 = yes
string_mask = utf8only

[req_distinguished_name]
CN = {$config['commonName']}
O = {$config['organizationName']}
OU = {$config['organizationUnitName']}
C = {$config['countryName']}

[v3_req]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation, keyEncipherment
extendedKeyUsage = serverAuth, clientAuth
subjectAltName = @alt_names
certificateTemplateName = ASN1:PRINTABLESTRING:ZATCA-Code-Signing

[alt_names]
dirName.1 = dir_sect

[dir_sect]
2.5.4.4 = UTF8:{$config['commonName']}
2.5.4.97 = UTF8:{$config['organizationIdentifier']}
2.5.4.12 = UTF8:{$config['invoiceType']}
2.5.4.26 = UTF8:{$config['location']}
2.5.4.15 = UTF8:{$config['industry']}
EOT;

        $configPath = storage_path('app/zatca/openssl.cnf');
        file_put_contents($configPath, $configContent);

        return $configPath;
    }
}

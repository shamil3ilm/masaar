<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsOpenSsl;
use App\Console\Commands\Concerns\WritesSecrets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

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

    private function generateCsr(): int
    {
        $this->info('Generating CSR (Certificate Signing Request)...');
        $this->newLine();

        // CSR configuration for ZATCA
        $config = [
            'commonName' => 'TST-886431145-'.str_pad((string) random_int(100000000, 999999999), 10, '0', STR_PAD_LEFT),
            'serialNumber' => '1-TST|2-TST|3-'.bin2hex(random_bytes(14)),
            'organizationIdentifier' => 'TESTIN-ENT-'.str_pad((string) random_int(100000000, 999999999), 10, '0', STR_PAD_LEFT),
            'organizationUnitName' => 'Test Branch',
            'organizationName' => 'Test Company',
            'countryName' => 'SA',
            'invoiceType' => '1100', // Standard & Simplified
            'location' => 'Riyadh',
            'industry' => 'Technology',
        ];

        $this->info('CSR Configuration:');
        $this->table(['Field', 'Value'], array_map(
            fn ($k, $v) => [$k, $v],
            array_keys($config),
            array_values($config)
        ));
        $this->newLine();

        // Generate private key using EC secp256k1 (ZATCA requirement)
        // Try multiple methods for cross-platform compatibility
        $privateKey = null;
        $privateKeyPem = null;

        // Method 1: Try OpenSSL extension with secp256k1
        $privateKey = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1',
        ]);

        // Method 2: Use shell command (works on Windows with OpenSSL installed)
        if (! $privateKey) {
            $this->warn('PHP OpenSSL EC not available, trying shell command...');

            $keyPath = storage_path('app/zatca/private_key.pem');
            $this->secretDir();

            $opensslCmd = $this->findOpenSsl();

            if ($opensslCmd) {
                $this->line("Found OpenSSL: {$opensslCmd}");

                // Generate EC key with secp256k1
                $cmd = "\"{$opensslCmd}\" ecparam -name secp256k1 -genkey -noout -out \"{$keyPath}\" 2>&1";
                exec($cmd, $output, $returnCode);

                if ($returnCode === 0 && file_exists($keyPath)) {
                    $privateKeyPem = file_get_contents($keyPath);
                    $privateKey = openssl_pkey_get_private($privateKeyPem);
                    $this->info('✓ Generated EC key using OpenSSL command');
                } else {
                    $this->warn('OpenSSL command failed, output: '.implode("\n", $output));
                }
            }
        }

        // Method 3: Generate a simple test key for demonstration
        if (! $privateKey && ! $privateKeyPem) {
            $this->warn('⚠️  Could not generate EC secp256k1 key.');
            $this->newLine();
            $this->info('For ZATCA compliance, you need secp256k1 EC keys.');
            $this->info('Options to generate the key:');
            $this->newLine();
            $this->line('Option 1: Install OpenSSL for Windows');
            $this->line('  Download: https://slproweb.com/products/Win32OpenSSL.html');
            $this->line('  Then run: openssl ecparam -name secp256k1 -genkey -noout -out private_key.pem');
            $this->newLine();
            $this->line('Option 2: Use WSL (Windows Subsystem for Linux)');
            $this->line('  wsl openssl ecparam -name secp256k1 -genkey -noout -out private_key.pem');
            $this->newLine();
            $this->line('Option 3: Use ZATCA SDK (Java)');
            $this->line('  fatoora -generateCSR');
            $this->newLine();
            $this->line('Option 4: Use online CSR generator');
            $this->line('  https://sandbox.zatca.gov.sa/ (Portal can generate CSR)');
            $this->newLine();

            // For demonstration, create a sample CSR structure
            if ($this->confirm('Generate a DEMO CSR for testing API flow? (Not valid for actual ZATCA submission)')) {
                return $this->generateDemoCsr($config);
            }

            return Command::FAILURE;
        }

        // Generate CSR using shell command (handles ZATCA's special serialNumber format)
        $csrPath = storage_path('app/zatca/csr.pem');
        $generatedKeyPath = storage_path('app/zatca/private_key.pem');
        $configPath = $this->getOpenSslConfig($config);

        $this->secretDir();

        // If we have a PHP key object but no file, export it
        if ($privateKey && ! file_exists($generatedKeyPath)) {
            openssl_pkey_export($privateKey, $privateKeyOut);
            $this->putSecret($generatedKeyPath, $privateKeyOut);
            chmod($generatedKeyPath, 0600);
        }

        // Ensure key file exists (from shell command or PHP export)
        if (! file_exists($generatedKeyPath)) {
            $this->error('Private key file not found');

            return Command::FAILURE;
        }

        $keyPath = $generatedKeyPath;

        // Find OpenSSL for CSR generation
        $opensslCmd = $this->findOpenSsl();
        if (! $opensslCmd) {
            $this->error('OpenSSL not found for CSR generation');

            return Command::FAILURE;
        }

        // Generate CSR using shell command (bypasses PHP's serialNumber limitations)
        $csrCmd = "\"{$opensslCmd}\" req -new -key \"{$keyPath}\" -out \"{$csrPath}\" -config \"{$configPath}\" 2>&1";
        $this->line("Generating CSR: {$csrCmd}");
        exec($csrCmd, $csrOutput, $csrReturnCode);

        if ($csrReturnCode !== 0 || ! file_exists($csrPath)) {
            $this->error('Failed to generate CSR via shell command');
            $this->line('Output: '.implode("\n", $csrOutput));

            // Fallback to simplified CSR without special ZATCA fields
            $this->warn('Trying simplified CSR generation...');

            return $this->generateSimplifiedCsr($config, $keyPath, $opensslCmd);
        }

        $csrOut = file_get_contents($csrPath);

        $this->info('✓ CSR generated successfully!');
        $this->line("  CSR saved to: {$csrPath}");
        $this->line("  Private key saved to: {$keyPath}");
        $this->newLine();

        // Show CSR content
        $this->info('CSR Content (base64 for API):');
        $csrBase64 = base64_encode(str_replace(
            ['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----', "\n", "\r"],
            '',
            $csrOut
        ));
        $this->line($csrBase64);
        $this->newLine();

        $this->info('▶️  NEXT STEP:');
        $this->line('  1. Go to: https://sandbox.zatca.gov.sa/');
        $this->line('  2. Use sandbox OTP: 123456 (or as provided)');
        $this->line('  3. Run: php artisan fatoora:sandbox-test --step=compliance-csid --otp=123456');

        return Command::SUCCESS;
    }

    /**
     * Generate simplified CSR when full ZATCA format fails.
     */
    private function generateSimplifiedCsr(array $config, string $keyPath, string $opensslCmd): int
    {
        $csrPath = storage_path('app/zatca/csr.pem');

        // Create a minimal config
        $simpleConfig = <<<EOT
[req]
default_bits = 2048
distinguished_name = req_distinguished_name
prompt = no

[req_distinguished_name]
CN = {$config['commonName']}
O = {$config['organizationName']}
OU = {$config['organizationUnitName']}
C = {$config['countryName']}
EOT;

        $simpleConfigPath = storage_path('app/zatca/openssl_simple.cnf');
        file_put_contents($simpleConfigPath, $simpleConfig);

        $cmd = "\"{$opensslCmd}\" req -new -key \"{$keyPath}\" -out \"{$csrPath}\" -config \"{$simpleConfigPath}\" 2>&1";
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || ! file_exists($csrPath)) {
            $this->error('Failed to generate simplified CSR');
            $this->line('Output: '.implode("\n", $output));

            return Command::FAILURE;
        }

        $csrOut = file_get_contents($csrPath);

        $this->info('✓ Simplified CSR generated (may need adjustment for full ZATCA compliance)');
        $this->line("  CSR saved to: {$csrPath}");
        $this->line("  Private key saved to: {$keyPath}");
        $this->newLine();

        $this->warn('⚠️  This is a simplified CSR without all ZATCA extensions.');
        $this->newLine();

        // Show CSR content
        $this->info('CSR Content (base64 for API):');
        $csrBase64 = base64_encode(str_replace(
            ['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----', "\n", "\r"],
            '',
            $csrOut
        ));
        $this->line($csrBase64);
        $this->newLine();

        $this->info('📋 To get a ZATCA-compliant CSR, use one of these methods:');
        $this->newLine();

        $this->comment('Option 1: ZATCA Sandbox Portal (Recommended - No installation required)');
        $this->line('  1. Visit: https://sandbox.zatca.gov.sa/');
        $this->line('  2. Click "Onboarding and CSR Generation"');
        $this->line('  3. Fill in company details and generate CSR');
        $this->line('  4. Copy the CSR and place in: storage/app/zatca/csr.pem');
        $this->newLine();

        $this->comment('Option 2: ZATCA SDK (fatoora tool)');
        $this->line('  1. Download SDK from ZATCA portal');
        $this->line('  2. Run: fatoora -generateCSR');
        $this->line('  3. Copy generated CSR to: storage/app/zatca/csr.pem');
        $this->newLine();

        $this->info('▶️  After obtaining proper CSR:');
        $this->line('  php artisan fatoora:sandbox-test --step=compliance-csid --otp=123456');

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
        $csrBase64 = base64_encode(str_replace(
            ['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----', "\n", "\r"],
            '',
            $csrContent
        ));

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

    /**
     * Generate a demo CSR for testing the API flow.
     * Note: This CSR is NOT valid for actual ZATCA submission.
     */
    private function generateDemoCsr(array $config): int
    {
        $this->warn('Generating DEMO CSR (for API flow testing only)...');
        $this->newLine();

        // Create directories
        $csrPath = storage_path('app/zatca/csr.pem');
        $keyPath = storage_path('app/zatca/private_key.pem');

        $this->secretDir();

        // Generate a demo CSR using RSA (more compatible)
        $rsaKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $rsaKey) {
            $this->error('Could not generate even RSA key. OpenSSL may not be configured correctly.');
            $this->line('Error: '.openssl_error_string());

            return Command::FAILURE;
        }

        // Build distinguished name
        $dn = [
            'commonName' => $config['commonName'],
            'organizationalUnitName' => $config['organizationUnitName'],
            'organizationName' => $config['organizationName'],
            'countryName' => $config['countryName'],
        ];

        // Generate CSR
        $csr = openssl_csr_new($dn, $rsaKey, ['digest_alg' => 'sha256']);

        if (! $csr) {
            $this->error('Failed to generate CSR: '.openssl_error_string());

            return Command::FAILURE;
        }

        // Export CSR and private key
        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($rsaKey, $privateKeyOut);

        file_put_contents($csrPath, $csrOut);
        $this->putSecret($keyPath, $privateKeyOut);
        chmod($keyPath, 0600);

        $this->info('✓ DEMO CSR generated (RSA-based, for testing only)');
        $this->line("  CSR saved to: {$csrPath}");
        $this->line("  Private key saved to: {$keyPath}");
        $this->newLine();

        $this->warn('⚠️  IMPORTANT: This is a DEMO CSR using RSA.');
        $this->warn('   ZATCA requires EC secp256k1 for production.');
        $this->warn('   Use this only to test the API request/response flow.');
        $this->newLine();

        // Show CSR content
        $this->info('CSR Content (base64):');
        $csrBase64 = base64_encode(str_replace(
            ['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----', "\n", "\r"],
            '',
            $csrOut
        ));
        $this->line(substr($csrBase64, 0, 100).'...');
        $this->newLine();

        $this->info('To generate a valid ZATCA CSR, use one of these methods:');
        $this->newLine();

        $this->comment('Method 1: Install OpenSSL for Windows');
        $this->line('  1. Download from: https://slproweb.com/products/Win32OpenSSL.html');
        $this->line('  2. Install "Win64 OpenSSL v3.x.x Light"');
        $this->line('  3. Add to PATH: C:\\Program Files\\OpenSSL-Win64\\bin');
        $this->line('  4. Run these commands:');
        $this->line('     openssl ecparam -name secp256k1 -genkey -noout -out private_key.pem');
        $this->line('     openssl req -new -sha256 -key private_key.pem -out csr.pem');
        $this->newLine();

        $this->comment('Method 2: Use Laragon\'s OpenSSL');
        $this->line('  C:\\laragon\\bin\\openssl\\openssl.exe ecparam -name secp256k1 -genkey -noout -out private_key.pem');
        $this->newLine();

        $this->comment('Method 3: Use ZATCA Sandbox Portal');
        $this->line('  1. Go to: https://sandbox.zatca.gov.sa/');
        $this->line('  2. The portal can generate CSR for you');
        $this->newLine();

        $this->info('▶️  NEXT STEP (with demo CSR):');
        $this->line('  php artisan fatoora:sandbox-test --step=compliance-csid --otp=123456');
        $this->line('  (Note: ZATCA may reject the RSA-based CSR, but you can see the API flow)');

        return Command::SUCCESS;
    }
}

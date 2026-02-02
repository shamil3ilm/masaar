<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
 *   php artisan zatca:sandbox-test --step=info
 *   php artisan zatca:sandbox-test --step=generate-csr
 *   php artisan zatca:sandbox-test --step=compliance-csid --otp=123456
 *   php artisan zatca:sandbox-test --step=compliance-check --invoice-hash=xxx
 */
class ZatcaSandboxTest extends Command
{
    protected $signature = 'zatca:sandbox-test
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
            [self::SANDBOX_BASE_URL . self::SANDBOX_COMPLIANCE_CSID, 'Get Compliance CSID'],
            [self::SANDBOX_BASE_URL . self::SANDBOX_COMPLIANCE_CHECK, 'Submit Compliance Invoices'],
            [self::SANDBOX_BASE_URL . self::SANDBOX_PRODUCTION_CSID, 'Get Production CSID'],
            [self::SANDBOX_BASE_URL . self::SANDBOX_REPORTING, 'Report B2C Invoices'],
            [self::SANDBOX_BASE_URL . self::SANDBOX_CLEARANCE, 'Clear B2B Invoices'],
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
        $this->line('  php artisan zatca:sandbox-test --step=generate-csr');

        return Command::SUCCESS;
    }

    private function generateCsr(): int
    {
        $this->info('Generating CSR (Certificate Signing Request)...');
        $this->newLine();

        // CSR configuration for ZATCA
        $config = [
            'commonName' => 'TST-886431145-' . str_pad((string) random_int(100000000, 999999999), 10, '0', STR_PAD_LEFT),
            'serialNumber' => '1-TST|2-TST|3-' . bin2hex(random_bytes(14)),
            'organizationIdentifier' => 'TESTIN-ENT-' . str_pad((string) random_int(100000000, 999999999), 10, '0', STR_PAD_LEFT),
            'organizationUnitName' => 'Test Branch',
            'organizationName' => 'Test Company',
            'countryName' => 'SA',
            'invoiceType' => '1100', // Standard & Simplified
            'location' => 'Riyadh',
            'industry' => 'Technology',
        ];

        $this->info('CSR Configuration:');
        $this->table(['Field', 'Value'], array_map(
            fn($k, $v) => [$k, $v],
            array_keys($config),
            array_values($config)
        ));
        $this->newLine();

        // Generate private key
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1',
        ]);

        if (!$privateKey) {
            $this->error('Failed to generate private key: ' . openssl_error_string());
            return Command::FAILURE;
        }

        // Build distinguished name
        $dn = [
            'commonName' => $config['commonName'],
            'serialNumber' => $config['serialNumber'],
            'UID' => $config['organizationIdentifier'],
            'organizationalUnitName' => $config['organizationUnitName'],
            'organizationName' => $config['organizationName'],
            'countryName' => $config['countryName'],
        ];

        // Generate CSR
        $csr = openssl_csr_new($dn, $privateKey, [
            'digest_alg' => 'sha256',
            'config' => $this->getOpenSslConfig($config),
        ]);

        if (!$csr) {
            $this->error('Failed to generate CSR: ' . openssl_error_string());
            return Command::FAILURE;
        }

        // Export CSR and private key
        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($privateKey, $privateKeyOut);

        // Save files
        $csrPath = storage_path('app/zatca/csr.pem');
        $keyPath = storage_path('app/zatca/private_key.pem');

        if (!is_dir(dirname($csrPath))) {
            mkdir(dirname($csrPath), 0755, true);
        }

        file_put_contents($csrPath, $csrOut);
        file_put_contents($keyPath, $privateKeyOut);
        chmod($keyPath, 0600); // Restrict access

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
        $this->line('  3. Run: php artisan zatca:sandbox-test --step=compliance-csid --otp=123456');

        return Command::SUCCESS;
    }

    private function getComplianceCsid(): int
    {
        $otp = $this->option('otp');

        if (!$otp) {
            $this->error('OTP is required. Use --otp=123456');
            $this->line('For sandbox testing, the OTP is usually: 123456');
            return Command::FAILURE;
        }

        $csrPath = storage_path('app/zatca/csr.pem');
        if (!file_exists($csrPath)) {
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

        $url = self::SANDBOX_BASE_URL . self::SANDBOX_COMPLIANCE_CSID;

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

            $this->info('Response Status: ' . $response->status());
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
                    ['CSID', substr($data['binarySecurityToken'] ?? '', 0, 50) . '...'],
                    ['Secret', substr($data['secret'] ?? '', 0, 20) . '...'],
                ]);
                $this->newLine();

                $this->info('Files saved:');
                $this->line("  CSID: {$csidPath}");
                $this->line("  Secret: {$secretPath}");
                $this->newLine();

                $this->info('▶️  NEXT STEP:');
                $this->line('  php artisan zatca:sandbox-test --step=compliance-check');
            } else {
                $this->error('Failed to get CSID');
                $this->line('Response: ' . $response->body());
            }
        } catch (\Exception $e) {
            $this->error('Request failed: ' . $e->getMessage());
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

        if (!file_exists($csidPath) || !file_exists($secretPath)) {
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
        $this->line('2. Submit to: ' . self::SANDBOX_BASE_URL . self::SANDBOX_COMPLIANCE_CHECK);
        $this->line('3. Use Basic Auth with CSID:Secret');
        $this->newLine();

        $this->info('API Request Format:');
        $this->line('POST ' . self::SANDBOX_BASE_URL . self::SANDBOX_COMPLIANCE_CHECK);
        $this->line('Headers:');
        $this->line('  Authorization: Basic ' . base64_encode($csid . ':' . $secret));
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
            ['B2B (Standard)', self::SANDBOX_BASE_URL . self::SANDBOX_CLEARANCE, 'POST'],
            ['B2C (Simplified)', self::SANDBOX_BASE_URL . self::SANDBOX_REPORTING, 'POST'],
        ]);

        return Command::SUCCESS;
    }

    private function getOpenSslConfig(array $config): string
    {
        $configContent = <<<EOT
[req]
default_bits = 2048
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
CN = {$config['commonName']}
serialNumber = {$config['serialNumber']}
UID = {$config['organizationIdentifier']}
OU = {$config['organizationUnitName']}
O = {$config['organizationName']}
C = {$config['countryName']}

[v3_req]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, nonRepudiation
subjectAltName = @alt_names
1.3.6.1.4.1.311.20.2 = ASN1:UTF8String:ZATCA-Code-Signing
2.5.29.17 = ASN1:UTF8String:1-{$config['invoiceType']}|2-{$config['location']}|3-{$config['industry']}

[alt_names]
dirName = dir_sect

[dir_sect]
SN = {$config['serialNumber']}
UID = {$config['organizationIdentifier']}
title = {$config['invoiceType']}
registeredAddress = {$config['location']}
businessCategory = {$config['industry']}
EOT;

        $configPath = storage_path('app/zatca/openssl.cnf');
        file_put_contents($configPath, $configContent);

        return $configPath;
    }
}

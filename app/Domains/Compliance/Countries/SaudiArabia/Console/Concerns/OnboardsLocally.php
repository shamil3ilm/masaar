<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console\Concerns;

use App\Domains\Compliance\Countries\SaudiArabia\Console\SdkValidator;
use App\Support\Xml;
use Illuminate\Console\Command;

/**
 * fatoora:onboard --target=local: the same steps with no call to ZATCA.
 *
 * A self-signed certificate stands in for each CSID and the compliance check
 * runs through ZATCA's SDK when it is installed, so the flow can be exercised
 * without an OTP or network access.
 *
 * Needs FindsOpenSsl and StoresCsids alongside it.
 */
trait OnboardsLocally
{
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
                $this->error('OpenSSL not found. Install it and put it on the PATH, or set OPENSSL_BIN to its full path.');

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

    /**
     * Check a document locally, through ZATCA's SDK when it is installed.
     *
     * The SDK's schema, EN16931 and KSA stages judge what the document says,
     * and they decide the result. Its certificate, QR, signature and PIH checks
     * need a ZATCA-issued certificate, so with the local one they fail for
     * reasons that are not about the document, and they are not counted.
     *
     * Without the SDK this can only confirm the XML is well formed and carries
     * the elements every invoice needs, and the message says which it was.
     */
    private function validateInvoiceLocally(string $xml, string $uuid, SdkValidator $sdk): array
    {
        // Save XML for debugging
        $debugPath = storage_path("app/zatca/debug_{$uuid}.xml");
        file_put_contents($debugPath, $xml);

        if ($sdk->available()) {
            $result = $sdk->validate($xml);

            if ($sdk->contentPassed($result)) {
                return ['success' => true, 'message' => 'SDK: XSD, EN and KSA passed'];
            }

            $failed = array_values(array_filter(
                SdkValidator::CONTENT_STAGES,
                fn (string $stage): bool => ($result['stages'][$stage] ?? null) !== 'PASSED'
            ));

            return [
                'success' => false,
                'message' => 'SDK: '.implode(', ', $failed).' failed. '.($result['errors'][0] ?? "See {$debugPath}"),
            ];
        }

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
}

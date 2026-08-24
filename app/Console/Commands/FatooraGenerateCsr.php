<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesSecrets;
use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use phpseclib3\Crypt\EC;
use phpseclib3\File\X509;

/**
 * Generate CSR and Private Key for ZATCA onboarding.
 *
 * This command generates a ZATCA-compliant CSR without requiring the SDK.
 * The generated CSR can be used with the fatoora:onboard command.
 *
 * Usage:
 *   php artisan fatoora:generate-csr
 *   php artisan fatoora:generate-csr --vat=399999999900003 --org="My Company"
 */
class FatooraGenerateCsr extends Command
{
    use WritesSecrets;

    protected $signature = 'fatoora:generate-csr
                            {--vat=399999999900003 : 15-digit VAT number (starts/ends with 3)}
                            {--org=Maximum Speed Tech Supply LTD : Organization name}
                            {--unit=IT Department : Organization unit/branch}
                            {--cn=EGS1-TEST-001 : Common name (EGS serial number)}
                            {--sn=1-Solution_2-1.0_3-ed22f1d8-e6a2-1118-9b58-d9a8195e990f : Solution serial number}
                            {--location=Riyadh : Branch location}
                            {--industry=Information Technology : Business category}
                            {--standard : Support standard invoices (B2B)}
                            {--simplified : Support simplified invoices (B2C)}
                            {--output= : Output directory (default: storage/app/zatca)}';

    protected $description = 'Generate ZATCA-compliant CSR and private key using PHP OpenSSL';

    private const SDK_PATH = 'C:/Users/Shamil/Downloads/zatca-einvoicing-sdk-Java-238-R3.4.8/zatca-einvoicing-sdk-Java-238-R3.4.8/Apps';

    public function handle(): int
    {
        $this->info('Generating ZATCA-compliant CSR and Private Key...');
        $this->newLine();

        // Determine invoice types - default to both if neither specified
        $invoiceTypesStandard = $this->option('standard');
        $invoiceTypesSimplified = $this->option('simplified');
        if (! $invoiceTypesStandard && ! $invoiceTypesSimplified) {
            $invoiceTypesStandard = true;
            $invoiceTypesSimplified = true;
        }

        // Build CSR data
        $csrData = new CsrData(
            organizationName: $this->option('org'),
            organizationUnit: $this->option('unit'),
            commonName: $this->option('cn'),
            vatNumber: $this->option('vat'),
            serialNumber: str_replace('_', '|', $this->option('sn')),
            location: $this->option('location'),
            industry: $this->option('industry'),
            invoiceTypesStandard: $invoiceTypesStandard,
            invoiceTypesSimplified: $invoiceTypesSimplified,
        );

        // Validate VAT number
        $vatNumber = $csrData->vatNumber;
        if (strlen($vatNumber) !== 15 || ! preg_match('/^3\d{13}3$/', $vatNumber)) {
            $this->error('VAT number must be 15 digits starting and ending with 3');
            $this->line('Example: 399999999900003');

            return Command::FAILURE;
        }

        // Display configuration
        $this->info('CSR Configuration:');
        $this->table(['Field', 'Value'], [
            ['Organization', $csrData->organizationName],
            ['Unit', $csrData->organizationUnit],
            ['Common Name', $csrData->commonName],
            ['VAT Number', $csrData->vatNumber],
            ['Location', $csrData->location],
            ['Industry', $csrData->industry],
            ['Invoice Types', $this->getInvoiceTypesDescription($invoiceTypesStandard, $invoiceTypesSimplified)],
        ]);
        $this->newLine();

        try {
            // Check if ZATCA SDK is available
            $sdkJar = self::SDK_PATH.'/zatca-einvoicing-sdk-238-R3.4.8.jar';
            if (file_exists($sdkJar)) {
                $this->info('Using ZATCA SDK for CSR generation (recommended)...');
                $result = $this->generateCsrWithSdk($csrData);
            } else {
                $this->warn('ZATCA SDK not found, falling back to phpseclib...');
                $this->line('For best results, install ZATCA SDK at: '.self::SDK_PATH);
                $result = $this->generateCsrWithPhpseclib($csrData);
            }

            $outputDir = $this->secretDir($this->option('output'));

            // The CSR carries the public key and is not secret.
            $csrPath = $outputDir.'/taxpayer.csr';
            File::put($csrPath, $result['csr']);
            $this->info("CSR saved to: {$csrPath}");

            // The key that will sign this taxpayer's invoices is.
            $keyPath = $outputDir.'/taxpayer.key';
            $this->putSecret($keyPath, $result['privateKey']);
            $this->info("Private key saved to: {$keyPath}");

            $this->newLine();
            $this->info('CSR and Private Key generated successfully!');
            $this->newLine();

            // Display next steps
            $this->info('Next Steps:');
            $this->line('1. Request CCSID with the generated CSR and key:');
            $this->line("   php artisan fatoora:onboard --step=ccsid --otp=<your-otp> --target=sandbox --csr={$csrPath} --key={$keyPath}");
            $this->newLine();
            $this->line('2. Run compliance check (invoices will be signed):');
            $this->line('   php artisan fatoora:onboard --step=compliance --target=sandbox');
            $this->newLine();

            // Show CSR content (first few lines)
            $this->info('CSR Preview:');
            $csrLines = explode("\n", $result['csr']);
            foreach (array_slice($csrLines, 0, 5) as $line) {
                $this->line($line);
            }
            $this->line('...');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to generate CSR: '.$e->getMessage());
            $this->newLine();
            $this->warn('Troubleshooting:');
            $this->line('1. Ensure OpenSSL extension is enabled in PHP');
            $this->line('2. Check that openssl.cnf path is configured in php.ini');
            $this->line('3. On Windows, you may need to set OPENSSL_CONF environment variable');

            return Command::FAILURE;
        }
    }

    private function getInvoiceTypesDescription(bool $standard, bool $simplified): string
    {
        $types = [];
        if ($standard) {
            $types[] = 'Standard (B2B)';
        }
        if ($simplified) {
            $types[] = 'Simplified (B2C)';
        }

        return implode(', ', $types);
    }

    /**
     * Generate CSR using the official ZATCA SDK.
     * This is the recommended approach as it produces CSRs that are guaranteed to be accepted.
     */
    private function generateCsrWithSdk(CsrData $csrData): array
    {
        $outputDir = $this->secretDir();

        // Create CSR config file for SDK
        $invoiceType = $csrData->getInvoiceTypeCode();
        $configPath = $outputDir.'/csr-config.properties';
        $configContent = <<<EOT
csr.common.name=TST-886431145-{$csrData->vatNumber}
csr.serial.number={$csrData->serialNumber}
csr.organization.identifier={$csrData->vatNumber}
csr.organization.unit.name={$csrData->organizationUnit}
csr.organization.name={$csrData->organizationName}
csr.country.name=SA
csr.invoice.type={$invoiceType}
csr.location.address={$csrData->location}
csr.industry.business.category={$csrData->industry}
EOT;
        file_put_contents($configPath, $configContent);

        $this->info('Created CSR config: '.$configPath);

        // Set up SDK environment
        $sdkConfigPath = dirname(self::SDK_PATH).'/Configuration/config.json';
        $sdkJar = self::SDK_PATH.'/zatca-einvoicing-sdk-238-R3.4.8.jar';

        // Ensure SDK config exists
        if (! file_exists($sdkConfigPath)) {
            $this->createSdkConfig($sdkConfigPath);
        }

        // Run SDK to generate CSR
        $csrOutput = $outputDir.'/taxpayer-sdk.csr';
        $keyOutput = $outputDir.'/taxpayer-sdk.key';

        $cmd = sprintf(
            'java -Djdk.module.illegalAccess=deny -Dfile.encoding=UTF-8 -jar "%s" --globalVersion 238-R3.4.8 -csr -csrConfig "%s" -generatedCsr "%s" -privateKey "%s" -sim 2>&1',
            $sdkJar,
            $configPath,
            basename($csrOutput),
            basename($keyOutput)
        );

        // Change to output directory and run
        $cwd = getcwd();
        chdir($outputDir);
        putenv('SDK_CONFIG='.$sdkConfigPath);

        $this->line('Running ZATCA SDK...');
        exec($cmd, $output, $returnCode);
        chdir($cwd);

        if ($returnCode !== 0) {
            throw new \RuntimeException('SDK CSR generation failed: '.implode("\n", $output));
        }

        $this->info('✓ CSR generated by ZATCA SDK');

        // SDK outputs:
        // - CSR: base64(PEM content) → decode to get PEM
        // - Key: base64(DER content) → needs PEM wrapping
        $csrBase64 = trim(file_get_contents($csrOutput));
        $keyBase64 = trim(file_get_contents($keyOutput));

        // CSR: decode base64 to get PEM
        $csrPem = base64_decode($csrBase64);

        // Key: base64 content is the DER, wrap with PEM headers
        // The SDK key is already base64-encoded DER, so wrap it directly
        $keyPem = "-----BEGIN EC PRIVATE KEY-----\n".
            chunk_split($keyBase64, 64, "\n").
            '-----END EC PRIVATE KEY-----';

        // Save PEM files
        $csrPath = $outputDir.'/taxpayer.csr';
        $keyPath = $outputDir.'/taxpayer.key';
        file_put_contents($csrPath, $csrPem);
        $this->putSecret($keyPath, $keyPem);

        $this->line("  serialNumber: {$csrData->serialNumber}");
        $this->line("  organizationIdentifier: VATSA-{$csrData->vatNumber}");

        return [
            'csr' => $csrPem,
            'privateKey' => $keyPem,
        ];
    }

    /**
     * Create SDK configuration file if it doesn't exist.
     */
    private function createSdkConfig(string $configPath): void
    {
        $sdkRoot = dirname(dirname(self::SDK_PATH));
        $config = [
            'xsdPath' => $sdkRoot.'/Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd',
            'enSchematron' => $sdkRoot.'/Data/Rules/schematrons/CEN-EN16931-UBL.xsl',
            'zatcaSchematron' => $sdkRoot.'/Data/Rules/schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl',
            'certPath' => $sdkRoot.'/Data/Certificates/cert.pem',
            'privateKeyPath' => $sdkRoot.'/Data/Certificates/ec-secp256k1-priv-key.pem',
            'pihPath' => $sdkRoot.'/Data/PIH/pih.txt',
            'inputPath' => $sdkRoot.'/Data/Input',
            'usagePathFile' => dirname($configPath).'/usage.txt',
        ];

        // Convert to forward slashes for cross-platform compatibility
        foreach ($config as $key => $value) {
            $config[$key] = str_replace('\\', '/', $value);
        }

        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Generate ZATCA-compliant CSR using shell commands with proper config.
     * This is the recommended approach as PHP's openssl functions don't properly add [alt_names].
     */
    private function generateZatcaCsr(CsrData $csrData): array
    {
        // Find OpenSSL executable
        $opensslCmd = $this->findOpenSsl();
        if (! $opensslCmd) {
            throw new \RuntimeException('OpenSSL not found. Please install OpenSSL or add it to PATH.');
        }

        $this->info("Using OpenSSL: {$opensslCmd}");

        $outputDir = $this->secretDir();

        $keyPath = $outputDir.'/taxpayer.key';
        $csrPath = $outputDir.'/taxpayer.csr';
        $configPath = $this->createFullZatcaConfig($csrData);

        // Generate EC private key using shell command (secp256k1 for ZATCA)
        $keyCmd = "\"{$opensslCmd}\" ecparam -name secp256k1 -genkey -noout -out \"{$keyPath}\" 2>&1";
        $this->line('Generating EC key: secp256k1');
        exec($keyCmd, $keyOutput, $keyReturnCode);

        if ($keyReturnCode !== 0 || ! file_exists($keyPath)) {
            // Try prime256v1 as fallback
            $this->warn('secp256k1 not available, trying prime256v1...');
            $keyCmd = "\"{$opensslCmd}\" ecparam -name prime256v1 -genkey -noout -out \"{$keyPath}\" 2>&1";
            exec($keyCmd, $keyOutput, $keyReturnCode);

            if ($keyReturnCode !== 0 || ! file_exists($keyPath)) {
                throw new \RuntimeException('Failed to generate EC private key: '.implode("\n", $keyOutput));
            }
        }

        $this->info('✓ Private key generated');

        // Generate CSR using shell command with ZATCA config
        $csrCmd = "\"{$opensslCmd}\" req -new -sha256 -key \"{$keyPath}\" -config \"{$configPath}\" -out \"{$csrPath}\" 2>&1";
        $this->line('Generating CSR with ZATCA extensions...');
        exec($csrCmd, $csrOutput, $csrReturnCode);

        if ($csrReturnCode !== 0 || ! file_exists($csrPath)) {
            $this->error('CSR generation failed: '.implode("\n", $csrOutput));
            throw new \RuntimeException('Failed to generate CSR with ZATCA extensions');
        }

        $this->info('✓ CSR generated with ZATCA extensions');

        // Read the generated files
        $privateKeyPem = file_get_contents($keyPath);
        $csrPem = file_get_contents($csrPath);

        // Show CSR details
        $this->line("  serialNumber: {$csrData->serialNumber}");
        $this->line("  organizationIdentifier: VATSA-{$csrData->vatNumber}");

        return [
            'csr' => $csrPem,
            'privateKey' => $privateKeyPem,
        ];
    }

    /**
     * Create full ZATCA-compliant OpenSSL config file with all required extensions.
     */
    private function createFullZatcaConfig(CsrData $csrData): string
    {
        // Organization identifier in ZATCA format
        $orgIdentifier = 'VATSA-'.$csrData->vatNumber;

        // Invoice type code (1100 = both standard and simplified)
        $invoiceType = $csrData->getInvoiceTypeCode();

        // Serial number with pipe characters - use UTF8 encoding
        $serialNumber = $csrData->serialNumber;

        // ZATCA CSR config with all required fields and extensions
        // Subject DN: C, OU, O, CN, serialNumber, organizationIdentifier
        // SAN extension: directoryName with SN, UID, title, etc.
        // Note: OpenSSL 3.x already has organizationIdentifier OID defined

        // Escape pipe characters using \x7C hex notation for OpenSSL
        $escapedSerialNumber = str_replace('|', '\\x7C', $serialNumber);

        $config = <<<EOT
# ZATCA E-Invoice CSR Configuration
oid_section = OIDs

[OIDs]
certificateTemplateName = 1.3.6.1.4.1.311.20.2

[req]
default_bits = 2048
req_extensions = v3_req
prompt = no
default_md = sha256
utf8 = yes
string_mask = utf8only
distinguished_name = dn

[dn]
C = SA
OU = {$csrData->organizationUnit}
O = {$csrData->organizationName}
CN = {$csrData->commonName}
serialNumber = {$escapedSerialNumber}
organizationIdentifier = {$orgIdentifier}

[v3_req]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation, keyEncipherment
certificateTemplateName = ASN1:PRINTABLESTRING:ZATCA-Code-Signing
subjectAltName = dirName:alt_names

[alt_names]
SN = {$escapedSerialNumber}
UID = {$csrData->vatNumber}
title = {$invoiceType}
registeredAddress = {$csrData->location}
businessCategory = {$csrData->industry}
EOT;

        $configPath = storage_path('app/zatca/zatca_csr.cnf');
        file_put_contents($configPath, $config);

        return $configPath;
    }

    /**
     * Generate CSR using phpseclib for ZATCA compliance.
     * This method properly includes serialNumber and organizationIdentifier in subject DN
     * with UTF8String encoding that supports pipe characters (|).
     * Note: This is a fallback - the SDK method is recommended for production use.
     */
    private function generateCsrWithPhpseclib(CsrData $csrData): array
    {
        // Generate EC private key with secp256k1 curve (ZATCA requirement)
        $privateKey = EC::createKey('secp256k1');

        $this->info('✓ EC private key generated (secp256k1)');

        // Organization identifier in ZATCA format: VATSA-{VAT number}
        $orgIdentifier = 'VATSA-'.$csrData->vatNumber;

        // Create X509 CSR
        $x509 = new X509;
        $x509->setPrivateKey($privateKey);

        // Set Distinguished Name with ZATCA-required fields
        // phpseclib handles UTF8String encoding properly for pipe characters
        $x509->setDN([
            'rdnSequence' => [
                [['type' => 'id-at-countryName', 'value' => ['printableString' => 'SA']]],
                [['type' => 'id-at-organizationName', 'value' => ['utf8String' => $csrData->organizationName]]],
                [['type' => 'id-at-organizationalUnitName', 'value' => ['utf8String' => $csrData->organizationUnit]]],
                [['type' => 'id-at-commonName', 'value' => ['utf8String' => $csrData->commonName]]],
                [['type' => 'id-at-serialNumber', 'value' => ['utf8String' => $csrData->serialNumber]]],
                [['type' => '2.5.4.97', 'value' => ['utf8String' => $orgIdentifier]]],
            ],
        ]);

        // Generate CSR with ECDSA signature
        // Note: Extensions are not included as phpseclib CSR doesn't support them easily
        // For full ZATCA compliance with extensions, use the SDK method
        $csr = $x509->signCSR();
        $csrPem = $x509->saveCSR($csr);
        $privateKeyPem = $privateKey->toString('PKCS8');

        $this->info('✓ CSR generated (without extensions - use SDK for full compliance)');
        $this->line("  serialNumber: {$csrData->serialNumber}");
        $this->line("  organizationIdentifier: {$orgIdentifier}");

        $outputDir = $this->secretDir();
        file_put_contents($outputDir.'/taxpayer.csr', $csrPem);
        $this->putSecret($outputDir.'/taxpayer.key', $privateKeyPem);

        return [
            'csr' => $csrPem,
            'privateKey' => $privateKeyPem,
        ];
    }

    /**
     * Generate a ZATCA-compliant CSR using shell commands.
     * This works on Windows when PHP's OpenSSL extension fails with custom extensions.
     */
    private function generateSimpleCsr(CsrData $csrData): array
    {
        // Find OpenSSL executable
        $opensslCmd = $this->findOpenSsl();
        if (! $opensslCmd) {
            throw new \RuntimeException('OpenSSL not found. Please install OpenSSL or add it to PATH.');
        }

        $this->info("Using OpenSSL: {$opensslCmd}");

        $outputDir = $this->secretDir();

        $keyPath = $outputDir.'/taxpayer.key';
        $csrPath = $outputDir.'/taxpayer.csr';

        // Generate EC private key using shell command (secp256k1 for ZATCA)
        $keyCmd = "\"{$opensslCmd}\" ecparam -name secp256k1 -genkey -noout -out \"{$keyPath}\" 2>&1";
        $this->line('Generating EC key: secp256k1');
        exec($keyCmd, $keyOutput, $keyReturnCode);

        if ($keyReturnCode !== 0 || ! file_exists($keyPath)) {
            // Try prime256v1 as fallback
            $this->warn('secp256k1 not available, trying prime256v1...');
            $keyCmd = "\"{$opensslCmd}\" ecparam -name prime256v1 -genkey -noout -out \"{$keyPath}\" 2>&1";
            exec($keyCmd, $keyOutput, $keyReturnCode);

            if ($keyReturnCode !== 0 || ! file_exists($keyPath)) {
                throw new \RuntimeException('Failed to generate EC private key: '.implode("\n", $keyOutput));
            }
        }

        $this->info('✓ Private key generated');

        // Create ZATCA-compliant OpenSSL config
        $configPath = $this->createZatcaConfig($csrData);

        // Generate CSR using shell command
        $csrCmd = "\"{$opensslCmd}\" req -new -sha256 -key \"{$keyPath}\" -out \"{$csrPath}\" -config \"{$configPath}\" 2>&1";
        $this->line('Generating CSR with ZATCA extensions...');
        exec($csrCmd, $csrOutput, $csrReturnCode);

        if ($csrReturnCode !== 0 || ! file_exists($csrPath)) {
            $this->warn('Full ZATCA config failed, trying simplified config...');
            $configPath = $this->createSimplifiedConfig($csrData);
            $csrCmd = "\"{$opensslCmd}\" req -new -sha256 -key \"{$keyPath}\" -out \"{$csrPath}\" -config \"{$configPath}\" 2>&1";
            exec($csrCmd, $csrOutput, $csrReturnCode);

            if ($csrReturnCode !== 0 || ! file_exists($csrPath)) {
                throw new \RuntimeException('Failed to generate CSR: '.implode("\n", $csrOutput));
            }
        }

        $this->info('✓ CSR generated');

        // Read the generated files
        $privateKeyPem = file_get_contents($keyPath);
        $csrPem = file_get_contents($csrPath);

        // Clean up temp config
        @unlink($configPath);

        $this->newLine();
        $this->info('Generated ZATCA-compliant CSR using OpenSSL shell commands');

        return [
            'csr' => $csrPem,
            'privateKey' => $privateKeyPem,
        ];
    }

    /**
     * Find OpenSSL executable on the system.
     */
    private function findOpenSsl(): ?string
    {
        $paths = [
            'openssl',
            'C:\\laragon\\bin\\git\\usr\\bin\\openssl.exe',
            'C:\\Program Files\\Git\\usr\\bin\\openssl.exe',
            'C:\\Program Files\\Git\\mingw64\\bin\\openssl.exe',
            'C:\\laragon\\bin\\openssl\\openssl.exe',
            'C:\\Program Files\\OpenSSL-Win64\\bin\\openssl.exe',
            'C:\\OpenSSL-Win64\\bin\\openssl.exe',
        ];

        foreach ($paths as $path) {
            exec("\"{$path}\" version 2>&1", $output, $code);
            if ($code === 0) {
                return $path;
            }
            $output = [];
        }

        return null;
    }

    /**
     * Create ZATCA-compliant OpenSSL config file.
     *
     * Note: The pipe character (|) in serialNumber is not valid in PrintableString.
     * We use the CN field to embed the combined identifier for sandbox testing.
     * For production, you may need the ZATCA SDK which handles ASN.1 encoding properly.
     */
    private function createZatcaConfig(CsrData $csrData): string
    {
        // For CN, use ZATCA format: TST-{timestamp}-{VAT}
        $commonName = 'TST-'.time().'-'.$csrData->vatNumber;

        // Serial number without pipes (replace with dashes for compatibility)
        $serialNumber = str_replace('|', '-', $csrData->serialNumber);

        $config = <<<EOT
# ZATCA CSR Configuration

[req]
default_bits = 2048
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no
utf8 = yes

[req_distinguished_name]
C = SA
O = {$csrData->organizationName}
OU = {$csrData->organizationUnit}
CN = {$commonName}
serialNumber = {$serialNumber}

[v3_req]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation
subjectAltName = @alt_names

[alt_names]
dirName = dir_sect

[dir_sect]
C = SA
O = {$csrData->organizationName}
OU = {$csrData->organizationUnit}
CN = {$csrData->commonName}
EOT;

        $configPath = storage_path('app/zatca/openssl_zatca.cnf');
        file_put_contents($configPath, $config);

        return $configPath;
    }

    /**
     * Create simplified OpenSSL config (fallback).
     */
    private function createSimplifiedConfig(CsrData $csrData): string
    {
        // Minimal config with just standard fields
        $config = <<<EOT
[req]
default_bits = 2048
distinguished_name = req_distinguished_name
prompt = no
utf8 = yes

[req_distinguished_name]
C = SA
O = {$csrData->organizationName}
OU = {$csrData->organizationUnit}
CN = {$csrData->commonName}
EOT;

        $configPath = storage_path('app/zatca/openssl_simple.cnf');
        file_put_contents($configPath, $config);

        return $configPath;
    }
}

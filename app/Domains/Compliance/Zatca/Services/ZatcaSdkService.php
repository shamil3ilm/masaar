<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use RuntimeException;

/**
 * Service for interacting with the official ZATCA SDK.
 *
 * The SDK provides:
 * - CSR generation
 * - Invoice signing
 * - Invoice validation
 * - QR code generation
 * - API request preparation
 */
class ZatcaSdkService
{
    private const SDK_PATH = null; // Use config('zatca.sdk_path') instead
    private const SDK_JAR = 'zatca-einvoicing-sdk-238-R3.4.8.jar';
    private const SDK_VERSION = '238-R3.4.8';

    private string $sdkPath;
    private string $configPath;
    private string $outputDir;

    public function __construct(?string $sdkPath = null, ?string $outputDir = null)
    {
        $this->sdkPath = $sdkPath ?? config('zatca.sdk_path', storage_path('app/zatca-sdk/Apps'));
        $this->outputDir = $outputDir ?? storage_path('app/zatca');
        $this->configPath = dirname($this->sdkPath) . '/Configuration/config.json';

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * Check if the SDK is available.
     */
    public function isAvailable(): bool
    {
        return file_exists($this->getSdkJarPath());
    }

    /**
     * Get the SDK JAR path.
     */
    public function getSdkJarPath(): string
    {
        return $this->sdkPath . '/' . self::SDK_JAR;
    }

    /**
     * Generate CSR using the SDK.
     *
     * @param array $config CSR configuration
     * @param bool $simulation Whether to generate for simulation environment
     * @return array{csr: string, privateKey: string}
     */
    public function generateCsr(array $config, bool $simulation = true): array
    {
        $this->ensureSdkAvailable();

        // Create CSR config file
        $configPath = $this->outputDir . '/csr-config.properties';
        $configContent = $this->buildCsrConfig($config);
        file_put_contents($configPath, $configContent);

        // Ensure SDK config exists
        $this->ensureSdkConfig();

        // Run SDK
        $csrOutput = $this->outputDir . '/taxpayer-sdk.csr';
        $keyOutput = $this->outputDir . '/taxpayer-sdk.key';
        $envFlag = $simulation ? '-sim' : '-nonprod';

        $cmd = $this->buildCommand([
            '-csr',
            '-csrConfig', basename($configPath),
            '-generatedCsr', basename($csrOutput),
            '-privateKey', basename($keyOutput),
            $envFlag,
        ]);

        $this->runCommand($cmd, $this->outputDir);

        // SDK outputs base64-encoded files
        $csrPem = base64_decode(file_get_contents($csrOutput));
        $keyPem = base64_decode(file_get_contents($keyOutput));

        // Save decoded versions
        file_put_contents($this->outputDir . '/taxpayer.csr', $csrPem);
        file_put_contents($this->outputDir . '/taxpayer.key', $keyPem);

        return [
            'csr' => $csrPem,
            'privateKey' => $keyPem,
        ];
    }

    /**
     * Sign an invoice using the SDK.
     *
     * @param string $invoiceXml Invoice XML content
     * @param string $certPath Path to certificate file
     * @param string $keyPath Path to private key file
     * @return array{signedInvoice: string, invoiceHash: string, qrCode: string}
     */
    public function signInvoice(string $invoiceXml, string $certPath, string $keyPath): array
    {
        $this->ensureSdkAvailable();

        // Save invoice to temp file
        $invoicePath = $this->outputDir . '/invoice_to_sign.xml';
        file_put_contents($invoicePath, $invoiceXml);

        $signedPath = $this->outputDir . '/signed_invoice.xml';

        // Update SDK config to use provided cert and key
        $this->updateSdkConfig($certPath, $keyPath);

        $cmd = $this->buildCommand([
            '-invoice', basename($invoicePath),
            '-sign',
            '-signedInvoice', basename($signedPath),
            '-qr',
        ]);

        $output = $this->runCommand($cmd, $this->outputDir);

        // Read signed invoice
        $signedInvoice = file_get_contents($signedPath);

        // Extract hash and QR from output
        $hash = $this->extractFromOutput($output, 'INVOICE HASH');
        $qrCode = $this->extractFromOutput($output, 'QR CODE');

        return [
            'signedInvoice' => $signedInvoice,
            'invoiceHash' => $hash,
            'qrCode' => $qrCode,
        ];
    }

    /**
     * Validate an invoice using the SDK.
     *
     * @param string $invoiceXml Invoice XML content
     * @return array{valid: bool, errors: array, warnings: array}
     */
    public function validateInvoice(string $invoiceXml): array
    {
        $this->ensureSdkAvailable();

        // Save invoice to temp file
        $invoicePath = $this->outputDir . '/invoice_to_validate.xml';
        file_put_contents($invoicePath, $invoiceXml);

        $cmd = $this->buildCommand([
            '-invoice', basename($invoicePath),
            '-validate',
        ]);

        $output = $this->runCommand($cmd, $this->outputDir, false);

        // Parse validation results
        $errors = [];
        $warnings = [];
        $valid = true;

        foreach ($output as $line) {
            if (str_contains($line, 'FAILED')) {
                $valid = false;
            }
            if (str_contains($line, '[ERROR]') && str_contains($line, 'CODE :')) {
                $errors[] = $line;
            }
            if (str_contains($line, '[WARN]')) {
                $warnings[] = $line;
            }
        }

        return [
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Generate API request JSON for invoice submission.
     */
    public function generateApiRequest(string $invoiceXml): array
    {
        $this->ensureSdkAvailable();

        // Save invoice to temp file
        $invoicePath = $this->outputDir . '/invoice_for_api.xml';
        file_put_contents($invoicePath, $invoiceXml);

        $requestPath = $this->outputDir . '/api_request.json';

        $cmd = $this->buildCommand([
            '-invoice', basename($invoicePath),
            '-invoiceRequest',
            '-apiRequest', basename($requestPath),
        ]);

        $this->runCommand($cmd, $this->outputDir);

        // Read and decode the API request
        $requestJson = file_get_contents($requestPath);
        return json_decode($requestJson, true);
    }

    /**
     * Build CSR configuration content.
     */
    private function buildCsrConfig(array $config): string
    {
        $lines = [];
        foreach ($config as $key => $value) {
            $lines[] = "{$key}={$value}";
        }
        return implode("\n", $lines);
    }

    /**
     * Build SDK command.
     */
    private function buildCommand(array $args): string
    {
        $jarPath = $this->getSdkJarPath();
        $argsStr = implode(' ', array_map(fn($a) => strpos($a, ' ') !== false ? "\"$a\"" : $a, $args));

        return sprintf(
            'java -Djdk.module.illegalAccess=deny -Dfile.encoding=UTF-8 -jar "%s" --globalVersion %s %s 2>&1',
            $jarPath,
            self::SDK_VERSION,
            $argsStr
        );
    }

    /**
     * Run SDK command.
     */
    private function runCommand(string $cmd, string $workDir, bool $throwOnError = true): array
    {
        $cwd = getcwd();
        chdir($workDir);
        putenv('SDK_CONFIG=' . $this->configPath);

        exec($cmd, $output, $returnCode);
        chdir($cwd);

        if ($throwOnError && $returnCode !== 0) {
            throw new RuntimeException('SDK command failed: ' . implode("\n", $output));
        }

        return $output;
    }

    /**
     * Ensure SDK is available.
     */
    private function ensureSdkAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException(
                'ZATCA SDK not found at: ' . $this->getSdkJarPath() .
                "\nPlease download and extract the SDK from ZATCA developer portal."
            );
        }
    }

    /**
     * Ensure SDK config file exists.
     */
    private function ensureSdkConfig(): void
    {
        if (!file_exists($this->configPath)) {
            $sdkRoot = dirname(dirname($this->sdkPath));
            $config = [
                'xsdPath' => $sdkRoot . '/Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd',
                'enSchematron' => $sdkRoot . '/Data/Rules/schematrons/CEN-EN16931-UBL.xsl',
                'zatcaSchematron' => $sdkRoot . '/Data/Rules/schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl',
                'certPath' => $sdkRoot . '/Data/Certificates/cert.pem',
                'privateKeyPath' => $sdkRoot . '/Data/Certificates/ec-secp256k1-priv-key.pem',
                'pihPath' => $sdkRoot . '/Data/PIH/pih.txt',
                'inputPath' => $sdkRoot . '/Data/Input',
                'usagePathFile' => dirname($this->configPath) . '/usage.txt',
            ];

            foreach ($config as $key => $value) {
                $config[$key] = str_replace('\\', '/', $value);
            }

            file_put_contents($this->configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Update SDK config with custom cert and key paths.
     */
    private function updateSdkConfig(string $certPath, string $keyPath): void
    {
        $config = json_decode(file_get_contents($this->configPath), true);
        $config['certPath'] = str_replace('\\', '/', $certPath);
        $config['privateKeyPath'] = str_replace('\\', '/', $keyPath);
        file_put_contents($this->configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Extract value from SDK output.
     */
    private function extractFromOutput(array $output, string $label): string
    {
        foreach ($output as $i => $line) {
            if (str_contains($line, $label) && isset($output[$i + 1])) {
                return trim($output[$i + 1]);
            }
        }
        return '';
    }
}

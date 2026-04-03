<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Client;

use App\Domains\Compliance\Fatoora\DTOs\CsidResponse;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Services\InvoiceHasher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ZATCA API client.
 *
 * Handles communication with ZATCA e-invoicing portal.
 * Supports sandbox, simulation, and production environments.
 */
class FatooraClient
{
    private string $baseUrl;
    private ?string $username;
    private ?string $password;
    private InvoiceHasher $hasher;

    /**
     * Get request timeout from config.
     */
    private function getTimeout(): int
    {
        return (int) config('fatoora.timeout', 30);
    }

    /**
     * Get connection timeout from config.
     */
    private function getConnectTimeout(): int
    {
        return (int) config('fatoora.connect_timeout', 10);
    }

    /**
     * Get retry attempts from config.
     */
    private function getRetryAttempts(): int
    {
        return (int) config('fatoora.retry_attempts', 3);
    }

    /**
     * Get retry delay from config.
     */
    private function getRetryDelay(): int
    {
        return (int) config('fatoora.retry_delay', 1000);
    }

    /**
     * Check if SSL verification is enabled.
     */
    private function isSslVerifyEnabled(): bool
    {
        return (bool) config('fatoora.ssl_verify', true);
    }

    public function __construct(?InvoiceHasher $hasher = null)
    {
        $this->hasher = $hasher ?? new InvoiceHasher();
        $environment = config('fatoora.environment', 'sandbox');
        $this->baseUrl = config("fatoora.endpoints.{$environment}");
        $this->username = config('fatoora.credentials.username');
        $this->password = config('fatoora.credentials.password');
    }

    /**
     * Submit invoice for clearance (B2B).
     */
    public function clearInvoice(string $invoiceXml, string $invoiceHash, string $uuid): FatooraResponse
    {
        return $this->submitInvoice('/invoices/clearance/single', $invoiceXml, $invoiceHash, $uuid);
    }

    /**
     * Report invoice (B2C).
     */
    public function reportInvoice(string $invoiceXml, string $invoiceHash, string $uuid): FatooraResponse
    {
        return $this->submitInvoice('/invoices/reporting/single', $invoiceXml, $invoiceHash, $uuid);
    }

    /**
     * Check compliance of invoice without submission.
     */
    public function checkCompliance(string $invoiceXml, string $invoiceHash, string $uuid): FatooraResponse
    {
        return $this->submitInvoice('/invoices/compliance', $invoiceXml, $invoiceHash, $uuid);
    }

    /**
     * Get invoice status by UUID.
     * Used to check clearance status for pending submissions.
     */
    public function getInvoiceStatus(string $uuid): array
    {
        try {
            $response = $this->httpClient()
                ->get($this->baseUrl . '/invoices/' . $uuid . '/status');

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('ZATCA status check failed', [
                'uuid' => $uuid,
                'status' => $response->status(),
            ]);

            return [
                'error' => true,
                'status_code' => $response->status(),
                'message' => 'Status check failed',
            ];

        } catch (\Exception $e) {
            Log::error('ZATCA status check exception', [
                'uuid' => $uuid,
                'message' => $e->getMessage(),
            ]);

            return [
                'error' => true,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Submit invoice to ZATCA API.
     */
    private function submitInvoice(string $endpoint, string $invoiceXml, string $invoiceHash, string $uuid): FatooraResponse
    {
        try {
            $response = $this->httpClient()
                ->post($this->baseUrl . $endpoint, [
                    'invoiceHash' => $invoiceHash,
                    'uuid' => $uuid,
                    'invoice' => base64_encode($invoiceXml),
                ]);

            if ($response->successful()) {
                return FatooraResponse::fromApiResponse($response->json());
            }

            Log::warning('ZATCA API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return FatooraResponse::failed(
                'ZATCA API returned status: ' . $response->status(),
                $response->body()
            );

        } catch (\Exception $e) {
            Log::error('ZATCA API exception', [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);

            return FatooraResponse::failed($e->getMessage());
        }
    }

    /**
     * Create base HTTP client with config-driven settings.
     * Used as foundation for all API requests.
     */
    private function createBaseHttpClient(): PendingRequest
    {
        $client = Http::timeout($this->getTimeout())
            ->connectTimeout($this->getConnectTimeout())
            ->retry($this->getRetryAttempts(), $this->getRetryDelay())
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Accept-Language' => 'en',
                'Accept-Version' => 'V2',
            ]);

        // Configure SSL verification
        if (! $this->isSslVerifyEnabled()) {
            $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Create HTTP client with authentication and retry logic.
     * Uses config-driven timeouts, retries, and SSL settings.
     */
    private function httpClient(): PendingRequest
    {
        $client = $this->createBaseHttpClient();

        // Add basic auth if credentials configured
        if ($this->username && $this->password) {
            $client->withBasicAuth($this->username, $this->password);
        }

        return $client;
    }

    /**
     * Request Compliance CSID (Step 1 of onboarding).
     */
    public function requestComplianceCsid(string $csr, string $otp): CsidResponse
    {
        try {
            $client = $this->createBaseHttpClient()
                ->withHeaders([
                    'OTP' => $otp,
                ]);

            $response = $client->post($this->baseUrl . '/compliance', [
                'csr' => base64_encode($csr),
            ]);

            if ($response->successful()) {
                return CsidResponse::fromApiResponse($response->json());
            }

            return CsidResponse::failed('CSID request failed: ' . $response->status());

        } catch (\Exception $e) {
            Log::error('Compliance CSID request failed', ['error' => $e->getMessage()]);
            return CsidResponse::failed($e->getMessage());
        }
    }

    /**
     * Submit compliance invoice for validation.
     */
    public function submitComplianceInvoice(string $invoiceXml, string $ccsid, string $secret): FatooraResponse
    {
        try {
            $client = $this->createBaseHttpClient()
                ->withBasicAuth($ccsid, $secret);

            $response = $client->post($this->baseUrl . '/compliance/invoices', [
                'invoiceHash' => $this->hashInvoice($invoiceXml),
                'uuid' => $this->extractUuid($invoiceXml),
                'invoice' => base64_encode($invoiceXml),
            ]);

            if ($response->successful()) {
                return FatooraResponse::fromApiResponse($response->json());
            }

            return FatooraResponse::failed('Compliance invoice submission failed: ' . $response->status());

        } catch (\Exception $e) {
            Log::error('Compliance invoice submission failed', ['error' => $e->getMessage()]);
            return FatooraResponse::failed($e->getMessage());
        }
    }

    /**
     * Request Production CSID (Step 3 of onboarding).
     */
    public function requestProductionCsid(string $ccsid, string $secret, string $requestId): CsidResponse
    {
        try {
            $client = $this->createBaseHttpClient()
                ->withBasicAuth($ccsid, $secret);

            $response = $client->post($this->baseUrl . '/production/csids', [
                'compliance_request_id' => $requestId,
            ]);

            if ($response->successful()) {
                return CsidResponse::fromApiResponse($response->json());
            }

            return CsidResponse::failed('PCSID request failed: ' . $response->status());

        } catch (\Exception $e) {
            Log::error('Production CSID request failed', ['error' => $e->getMessage()]);
            return CsidResponse::failed($e->getMessage());
        }
    }

    /**
     * Renew Production CSID before expiry.
     */
    public function renewProductionCsid(string $pcsid, string $secret, string $csr, string $otp): CsidResponse
    {
        try {
            $client = $this->createBaseHttpClient()
                ->withBasicAuth($pcsid, $secret)
                ->withHeaders(['OTP' => $otp]);

            $response = $client->patch($this->baseUrl . '/production/csids', [
                'csr' => base64_encode($csr),
            ]);

            if ($response->successful()) {
                return CsidResponse::fromApiResponse($response->json());
            }

            return CsidResponse::failed('PCSID renewal failed: ' . $response->status());

        } catch (\Exception $e) {
            Log::error('PCSID renewal failed', ['error' => $e->getMessage()]);
            return CsidResponse::failed($e->getMessage());
        }
    }

    /**
     * Check if client is configured.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== null
            && $this->username !== null
            && $this->password !== null;
    }

    /**
     * Get current environment.
     */
    public function getEnvironment(): string
    {
        return config('fatoora.environment', 'sandbox');
    }

    /**
     * Hash invoice XML for API submission.
     *
     * Uses proper C14N canonicalization per ZATCA specification.
     */
    private function hashInvoice(string $xml): string
    {
        return $this->hasher->hash($xml);
    }

    /**
     * Extract UUID from invoice XML.
     */
    private function extractUuid(string $xml): string
    {
        if (preg_match('/<cbc:UUID>([^<]+)<\/cbc:UUID>/i', $xml, $matches)) {
            return $matches[1];
        }

        return '';
    }
}

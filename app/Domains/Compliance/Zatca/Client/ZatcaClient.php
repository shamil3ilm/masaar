<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Client;

use App\Domains\Compliance\Zatca\DTOs\CsidResponse;
use App\Domains\Compliance\Zatca\DTOs\ZatcaResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ZATCA API client.
 *
 * Handles communication with ZATCA e-invoicing portal.
 * Supports sandbox, simulation, and production environments.
 */
class ZatcaClient
{
    private string $baseUrl;
    private ?string $username;
    private ?string $password;
    private int $timeout;
    private int $retryAttempts;
    private int $retryDelay;

    public function __construct()
    {
        $environment = config('zatca.environment', 'sandbox');
        $this->baseUrl = config("zatca.endpoints.{$environment}");
        $this->username = config('zatca.credentials.username');
        $this->password = config('zatca.credentials.password');
        $this->timeout = config('zatca.timeout', 30);
        $this->retryAttempts = config('zatca.retry_attempts', 3);
        $this->retryDelay = config('zatca.retry_delay', 1000);
    }

    /**
     * Submit invoice for clearance (B2B).
     */
    public function clearInvoice(string $invoiceXml, string $invoiceHash, string $uuid): ZatcaResponse
    {
        return $this->submitInvoice('/invoices/clearance/single', $invoiceXml, $invoiceHash, $uuid);
    }

    /**
     * Report invoice (B2C).
     */
    public function reportInvoice(string $invoiceXml, string $invoiceHash, string $uuid): ZatcaResponse
    {
        return $this->submitInvoice('/invoices/reporting/single', $invoiceXml, $invoiceHash, $uuid);
    }

    /**
     * Check compliance of invoice without submission.
     */
    public function checkCompliance(string $invoiceXml, string $invoiceHash, string $uuid): ZatcaResponse
    {
        return $this->submitInvoice('/invoices/compliance', $invoiceXml, $invoiceHash, $uuid);
    }

    /**
     * Submit invoice to ZATCA API.
     */
    private function submitInvoice(string $endpoint, string $invoiceXml, string $invoiceHash, string $uuid): ZatcaResponse
    {
        try {
            $response = $this->httpClient()
                ->post($this->baseUrl . $endpoint, [
                    'invoiceHash' => $invoiceHash,
                    'uuid' => $uuid,
                    'invoice' => base64_encode($invoiceXml),
                ]);

            if ($response->successful()) {
                return ZatcaResponse::fromApiResponse($response->json());
            }

            Log::warning('ZATCA API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ZatcaResponse::failed(
                'ZATCA API returned status: ' . $response->status(),
                $response->body()
            );

        } catch (\Exception $e) {
            Log::error('ZATCA API exception', [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);

            return ZatcaResponse::failed($e->getMessage());
        }
    }

    /**
     * Create HTTP client with authentication and retry logic.
     */
    private function httpClient(): PendingRequest
    {
        $client = Http::timeout($this->timeout)
            ->retry($this->retryAttempts, $this->retryDelay)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Accept-Language' => 'en',
                'Accept-Version' => 'V2',
            ]);

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
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'OTP' => $otp,
                    'Accept-Version' => 'V2',
                ])
                ->post($this->baseUrl . '/compliance', [
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
    public function submitComplianceInvoice(string $invoiceXml, string $ccsid, string $secret): ZatcaResponse
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($ccsid, $secret)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Accept-Language' => 'en',
                    'Accept-Version' => 'V2',
                ])
                ->post($this->baseUrl . '/compliance/invoices', [
                    'invoiceHash' => $this->hashInvoice($invoiceXml),
                    'uuid' => $this->extractUuid($invoiceXml),
                    'invoice' => base64_encode($invoiceXml),
                ]);

            if ($response->successful()) {
                return ZatcaResponse::fromApiResponse($response->json());
            }

            return ZatcaResponse::failed('Compliance invoice submission failed: ' . $response->status());

        } catch (\Exception $e) {
            Log::error('Compliance invoice submission failed', ['error' => $e->getMessage()]);
            return ZatcaResponse::failed($e->getMessage());
        }
    }

    /**
     * Request Production CSID (Step 3 of onboarding).
     */
    public function requestProductionCsid(string $ccsid, string $secret, string $requestId): CsidResponse
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($ccsid, $secret)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Accept-Version' => 'V2',
                ])
                ->post($this->baseUrl . '/production/csids', [
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
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($pcsid, $secret)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'OTP' => $otp,
                    'Accept-Version' => 'V2',
                ])
                ->patch($this->baseUrl . '/production/csids', [
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
        return config('zatca.environment', 'sandbox');
    }

    /**
     * Hash invoice XML for API submission.
     */
    private function hashInvoice(string $xml): string
    {
        return base64_encode(hash('sha256', $xml, true));
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

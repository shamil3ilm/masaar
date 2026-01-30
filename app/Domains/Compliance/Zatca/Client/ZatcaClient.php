<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Client;

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
}

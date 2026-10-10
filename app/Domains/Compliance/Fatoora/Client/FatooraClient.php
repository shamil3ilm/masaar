<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Client;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\DTOs\CsidResponse;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Services\InvoiceHasher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
        $this->hasher = $hasher ?? new InvoiceHasher;
        $this->baseUrl = FatooraConfig::getBaseUrl();
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
                ->get($this->baseUrl.'/invoices/'.$uuid.'/status');

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
                ->post($this->baseUrl.$endpoint, [
                    'invoiceHash' => $invoiceHash,
                    'uuid' => $uuid,
                    'invoice' => base64_encode($invoiceXml),
                ]);

            if ($response->successful()) {
                return FatooraResponse::fromApiResponse($response->json());
            }

            // Being throttled is not being refused.
            //
            // Every unsuccessful response became a generic failure, and
            // SubmissionLedger turns any unsuccessful response into
            // 'rejected' - so a device ZATCA was rate limiting looked exactly
            // like a document ZATCA had refused, in the one state an operator
            // reads. ErrorCode::ZATCA_RATE_LIMITED existed for this and was
            // never assigned to anything.
            if ($throttle = $this->throttle($response)) {
                Log::warning('ZATCA is rate limiting this device', [
                    'status' => $response->status(),
                    'retry_after' => $throttle,
                ]);

                return FatooraResponse::throttled($throttle, $response->body());
            }

            Log::warning('ZATCA API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return FatooraResponse::failed(
                $this->refusal($response),
                $response->body(),
                $this->transient($response),
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
     * How long to wait, if this response is the authority declining to look.
     *
     * Null when it is not. 429 is the documented refusal; 503 is treated the
     * same way, because a gateway shedding load is the same instruction with a
     * different number, and both are safe to retry - a submission is
     * idempotent, so retrying cannot double-report a document.
     *
     * Retry-After is honoured when present, in either form the HTTP
     * specification allows, so the wait is the authority's own figure rather
     * than a guess at its rate limit. Falls back to the delay the error code
     * carries when the header is absent or unreadable.
     */
    private function throttle(Response $response): ?int
    {
        if (! in_array($response->status(), [429, 503], true)) {
            return null;
        }

        $header = trim((string) $response->header('Retry-After'));

        if ($header !== '' && ctype_digit($header)) {
            return max(1, (int) $header);
        }

        // Or an HTTP date, which is how a gateway often phrases it.
        if ($header !== '' && ($at = strtotime($header)) !== false) {
            return max(1, $at - time());
        }

        return ErrorCode::ZATCA_RATE_LIMITED->getRetryDelay();
    }

    /**
     * The code for a response that is the authority faltering, not refusing.
     *
     * Null when the status says something about the document. This is the
     * same omission ZATCA_RATE_LIMITED had: these three cases exist, are
     * already listed as retryable, and were assigned by nothing - so a 500
     * from ZATCA arrived with no code at all, isRetryable() answered false,
     * and SubmissionLedger recorded the submission as 'rejected'.
     *
     * 'rejected' asserts the document was wrong. For a simplified invoice
     * that is reported within twenty-four hours, spending that window on a
     * gateway blip - and telling the operator the invoice was bad - is the
     * failure this distinction exists to prevent. The ledger already says so
     * in as many words; only the predicate feeding it was incomplete.
     *
     * 503 does not appear here because throttle() claims it first, with the
     * authority's own Retry-After. Retrying is safe either way: a submission
     * is idempotent, so it cannot double-report a document.
     */
    private function transient(Response $response): ?ErrorCode
    {
        return match ($response->status()) {
            408, 504 => ErrorCode::ZATCA_TIMEOUT,
            500, 502 => ErrorCode::ZATCA_SERVICE_UNAVAILABLE,
            default => null,
        };
    }

    /**
     * What the authority said, in preference to what HTTP said.
     *
     * A refusal names the rule it broke - "PIH is inValid", "Invalid signed
     * properties hashing" - and errorMessages is the one field an operator
     * reads. This reported 'ZATCA API returned status: 400' and left the
     * reasons in the raw body, where they are only found by someone who
     * already suspects they are there.
     */
    private function refusal(Response $response): string
    {
        $body = (array) $response->json();

        $reasons = array_filter(array_merge(
            array_column((array) ($body['errorMessages'] ?? []), 'message'),
            array_column((array) ($body['validationResults']['errorMessages'] ?? []), 'message'),
        ));

        if ($reasons === []) {
            return 'ZATCA API returned status: '.$response->status();
        }

        return sprintf(
            'ZATCA returned status %d: %s',
            $response->status(),
            implode('; ', array_slice($reasons, 0, 3))
        );
    }

    /**
     * Create base HTTP client with config-driven settings.
     * Used as foundation for all API requests.
     */
    private function createBaseHttpClient(): PendingRequest
    {
        $client = Http::timeout($this->getTimeout())
            ->connectTimeout($this->getConnectTimeout())
            // Retry the transport, never the answer.
            //
            // Http::retry() throws on a failed status when more than one
            // attempt is configured, so every non-2xx reply from ZATCA was
            // retried at a fixed delay inside the caller's request and then
            // left this method as an exception message - which made the
            // structured response handling below unreachable for every
            // failing status, including a 429 carrying the authority's own
            // Retry-After.
            //
            // A connection that never opened is worth retrying here; an
            // answer is not. Retrying an answer is the queue's job, which has
            // backoff, an idempotency key and a record of each attempt, and
            // for a 429 it can wait exactly as long as the authority asked.
            // throw: false returns the response rather than raising it.
            ->retry(
                $this->getRetryAttempts(),
                $this->getRetryDelay(),
                fn (\Throwable $e): bool => $e instanceof ConnectionException,
                throw: false,
            )
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

            $response = $client->post($this->baseUrl.'/compliance', [
                'csr' => base64_encode($csr),
            ]);

            if ($response->successful()) {
                return CsidResponse::fromApiResponse($response->json());
            }

            return CsidResponse::failed('CSID request failed: '.$response->status());

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

            $response = $client->post($this->baseUrl.'/compliance/invoices', [
                'invoiceHash' => $this->hashInvoice($invoiceXml),
                'uuid' => $this->extractUuid($invoiceXml),
                'invoice' => base64_encode($invoiceXml),
            ]);

            if ($response->successful()) {
                return FatooraResponse::fromApiResponse($response->json());
            }

            return FatooraResponse::failed('Compliance invoice submission failed: '.$response->status());

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

            $response = $client->post($this->baseUrl.'/production/csids', [
                'compliance_request_id' => $requestId,
            ]);

            if ($response->successful()) {
                return CsidResponse::fromApiResponse($response->json());
            }

            return CsidResponse::failed('PCSID request failed: '.$response->status());

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

            $response = $client->patch($this->baseUrl.'/production/csids', [
                'csr' => base64_encode($csr),
            ]);

            if ($response->successful()) {
                return CsidResponse::fromApiResponse($response->json());
            }

            return CsidResponse::failed('PCSID renewal failed: '.$response->status());

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

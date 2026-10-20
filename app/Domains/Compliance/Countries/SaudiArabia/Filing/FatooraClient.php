<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\CsidResponse;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\DeviceCredentials;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\InvoiceHasher;
use App\Support\Errors\ErrorCode;
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
 *
 * Every call authenticates as the device it is made for. Clearance and
 * reporting were sent with one platform-wide ZATCA_USERNAME and
 * ZATCA_PASSWORD, which ZATCA does not issue: it onboards each EGS unit and
 * gives it its own token and secret, and an invoice filed under another
 * device's credentials is filed as that device's.
 */
class FatooraClient
{
    /**
     * ZATCA's paths, relative to the environment's base URL. Stated once:
     * the onboarding commands name the same endpoints.
     */
    public const COMPLIANCE_CSID = '/compliance';

    public const COMPLIANCE_INVOICES = '/compliance/invoices';

    public const PRODUCTION_CSID = '/production/csids';

    public const CLEARANCE = '/invoices/clearance/single';

    public const REPORTING = '/invoices/reporting/single';

    private string $baseUrl;

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

    public function __construct(InvoiceHasher $hasher)
    {
        $this->hasher = $hasher;
        $this->baseUrl = FatooraConfig::getBaseUrl();
    }

    /**
     * Submit invoice for clearance (B2B).
     */
    public function clearInvoice(string $invoiceXml, string $invoiceHash, string $uuid, DeviceCredentials $device): FatooraResponse
    {
        return $this->submitInvoice(self::CLEARANCE, $invoiceXml, $invoiceHash, $uuid, $device);
    }

    /**
     * Report invoice (B2C).
     */
    public function reportInvoice(string $invoiceXml, string $invoiceHash, string $uuid, DeviceCredentials $device): FatooraResponse
    {
        return $this->submitInvoice(self::REPORTING, $invoiceXml, $invoiceHash, $uuid, $device);
    }

    /**
     * Check compliance of invoice without submission.
     *
     * Authenticated with the compliance CSID, as ZATCA requires. This posted
     * to /invoices/compliance, which is not ZATCA's path - onboarding and the
     * sandbox round trip use /compliance/invoices - so validation never
     * reached the authority.
     */
    public function checkCompliance(string $invoiceXml, string $invoiceHash, string $uuid, DeviceCredentials $device): FatooraResponse
    {
        return $this->submitInvoice(self::COMPLIANCE_INVOICES, $invoiceXml, $invoiceHash, $uuid, $device);
    }

    /**
     * Submit invoice to ZATCA API.
     */
    private function submitInvoice(string $endpoint, string $invoiceXml, string $invoiceHash, string $uuid, DeviceCredentials $device): FatooraResponse
    {
        try {
            $request = $this->createBaseHttpClient()->withBasicAuth($device->token, $device->secret);

            // Clearance asks the authority to stamp the document, and says so
            // with Clearance-Status: 1. Without it the clearance endpoint does
            // not clear: the sandbox onboarding command always sent it, and
            // this - the path every real standard invoice takes - did not.
            // Reporting must not carry it.
            if ($endpoint === self::CLEARANCE) {
                $request = $request->withHeaders(['Clearance-Status' => '1']);
            }

            $response = $request->post($this->baseUrl.$endpoint, [
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

            // "303 Clearance switched off - Please submit via reporting"
            // (Detailed Guidelines, responses and required actions). Not
            // cleared, so not filed twice: the same document is reported.
            if ($response->status() === 303 && $endpoint === self::CLEARANCE) {
                Log::warning('ZATCA has clearance switched off; reporting the document instead', ['uuid' => $uuid]);

                return $this->submitInvoice(self::REPORTING, $invoiceXml, $invoiceHash, $uuid, $device);
            }

            // The authority faltering is retried; the authority refusing the
            // document keeps every rule it named (FatooraResponse::refused).
            $transient = $this->transient($response);

            if ($transient !== null) {
                return FatooraResponse::failed($this->refusal($response), $response->body(), $transient);
            }

            $refused = FatooraResponse::refused($this->refusal($response), (array) $response->json(), $response->body());

            // Only a 400 that judged the document is a rejection. Anything
            // else the authority said is held with its reason, to be sent
            // again once fixed: 401 "check authentication certificate and
            // secret and resubmit", 413 "invoice not received".
            return $response->status() === 400 && $refused->isVerdict()
                ? $refused
                : $refused->withCode(in_array($response->status(), [401, 403], true)
                    ? ErrorCode::ZATCA_UNAUTHORIZED
                    : ErrorCode::ZATCA_NOT_PROCESSED);

        } catch (ConnectionException $e) {
            // No answer, so nothing was refused. Without a code this was
            // recorded as a rejection - and once the verdict is written to the
            // invoice, an outage would mark it Rejected too.
            //
            // Two different silences, though. A connection that never opened
            // carried nothing; a read that timed out may have delivered a
            // clearance ZATCA has since granted.
            $neverConnected = $this->neverConnected($e);

            Log::warning('ZATCA unreachable', [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint,
                'never_connected' => $neverConnected,
            ]);

            return FatooraResponse::failed(
                $e->getMessage(),
                errorCode: $neverConnected ? ErrorCode::ZATCA_SERVICE_UNAVAILABLE : ErrorCode::ZATCA_TIMEOUT,
                mayHaveArrived: ! $neverConnected,
            );
        } catch (\Exception $e) {
            Log::error('ZATCA API exception', [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);

            // Ours, not the authority's: no verdict, and it may have been
            // sent, so a clearance is held for reconciliation.
            return FatooraResponse::failed($e->getMessage(), errorCode: ErrorCode::SYS_INTERNAL_ERROR);
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
     * authority's own Retry-After.
     *
     * Retrying is safe, and that is observed rather than assumed: on
     * 2026-10-10 the same simplified document was reported to the sandbox
     * twice, byte for byte, and the authority answered HTTP 200 REPORTED with
     * validation PASS both times. So a retry after a timeout - where the
     * outcome of the first attempt is unknown by definition - cannot turn an
     * accepted document into a refused one. That property is what makes this
     * method safe to widen, and it is the one worth re-checking against
     * simulation when a taxpayer exists.
     */
    /**
     * Whether the request certainly never left: a name that did not resolve,
     * a connection refused or not established in time. cURL reports a read
     * that timed out as "Operation timed out", and that one may have arrived.
     */
    private function neverConnected(ConnectionException $e): bool
    {
        return preg_match(
            '/cURL error (6|7):|Could not resolve host|Failed to connect|Connection refused|Connection timed out/i',
            $e->getMessage()
        ) === 1;
    }

    private function transient(Response $response): ?ErrorCode
    {
        return match (true) {
            in_array($response->status(), [408, 504], true) => ErrorCode::ZATCA_TIMEOUT,
            // "Internal Server Error, invoice not received - Please resend",
            // and any other 5xx the gateway invents.
            $response->serverError() => ErrorCode::ZATCA_SERVICE_UNAVAILABLE,
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
            //
            // And only a connection that never opened: a read timeout is a
            // ConnectionException too, and it can follow a clearance ZATCA
            // has already granted.
            ->retry(
                $this->getRetryAttempts(),
                $this->getRetryDelay(),
                // Null for a 3xx, which carries no exception.
                fn (?\Throwable $e): bool => $e instanceof ConnectionException && $this->neverConnected($e),
                throw: false,
            )
            // A 303 is the authority's instruction (clearance switched off),
            // to be read here; followed, it turned the POST into a GET.
            ->withoutRedirecting()
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
     * Request Compliance CSID (Step 1 of onboarding).
     */
    public function requestComplianceCsid(string $csr, string $otp): CsidResponse
    {
        try {
            $client = $this->createBaseHttpClient()
                ->withHeaders([
                    'OTP' => $otp,
                ]);

            $response = $client->post($this->baseUrl.self::COMPLIANCE_CSID, [
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
        return $this->checkCompliance(
            $invoiceXml,
            $this->hashInvoice($invoiceXml),
            $this->extractUuid($invoiceXml),
            new DeviceCredentials($ccsid, $secret),
        );
    }

    /**
     * Request Production CSID (Step 3 of onboarding).
     */
    public function requestProductionCsid(string $ccsid, string $secret, string $requestId): CsidResponse
    {
        try {
            $client = $this->createBaseHttpClient()
                ->withBasicAuth($ccsid, $secret);

            $response = $client->post($this->baseUrl.self::PRODUCTION_CSID, [
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

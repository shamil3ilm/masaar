<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Client;

use App\Domains\Compliance\FTA\DTOs\FtaResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The UAE FTA e-invoicing API, and nothing else.
 *
 * Every answer is an FtaResponse. What the authority decided — accepted,
 * pending review, rejected — comes back as it was given. A server error, a
 * throttle, a refused credential or an unreachable host is not a decision
 * about the document, and comes back as FtaResponse::failed() so the
 * submission stays open to another attempt.
 */
class FtaClient
{
    /**
     * Statuses that say nothing about the document: the credential, the
     * timing or the rate was the problem.
     */
    private const NOT_A_VERDICT = [401, 403, 408, 429];

    public function submit(string $xml): FtaResponse
    {
        try {
            $response = $this->request()
                ->accept('application/json')
                ->withBody($xml, 'application/xml')
                ->post($this->baseUrl().'/invoices');
        } catch (ConnectionException $e) {
            return FtaResponse::failed('UAE FTA unreachable: '.$e->getMessage());
        }

        if ($this->undecided($response)) {
            return FtaResponse::failed('UAE FTA returned HTTP '.$response->status(), $response->body());
        }

        $body = $this->body($response);

        // Any other 4xx is the authority refusing the document, whether or
        // not the body says so.
        if ($response->clientError()) {
            $body['status'] = 'rejected';
            $body['errors'] = ($body['errors'] ?? []) ?: ['UAE FTA returned HTTP '.$response->status()];
        }

        return FtaResponse::fromApiResponse($body);
    }

    /**
     * The authority's current decision on an earlier submission.
     */
    public function status(string $reference): FtaResponse
    {
        try {
            $response = $this->request()
                ->accept('application/json')
                ->get($this->baseUrl().'/submissions/'.rawurlencode($reference).'/status');
        } catch (ConnectionException $e) {
            return FtaResponse::failed('UAE FTA unreachable: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return FtaResponse::failed('UAE FTA returned HTTP '.$response->status(), $response->body());
        }

        return FtaResponse::fromApiResponse($this->body($response));
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('fta.api_key', ''))
            ->timeout((int) config('fta.timeout', 30))
            ->connectTimeout((int) config('fta.connect_timeout', 10));
    }

    /**
     * The configured environment's endpoint, or the sandbox's for a name
     * config/fta.php does not define.
     */
    private function baseUrl(): string
    {
        $environment = config('fta.environment', 'sandbox');

        return (string) (config("fta.endpoints.{$environment}") ?? config('fta.endpoints.sandbox'));
    }

    private function undecided(Response $response): bool
    {
        return $response->serverError() || in_array($response->status(), self::NOT_A_VERDICT, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}

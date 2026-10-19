<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Gateways;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\DTOs\AspError;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspRefusedException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Support\SafeUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A generic REST provider: the starting point to adapt once one is chosen.
 *
 * No provider is chosen yet, so this speaks the shape most of them offer and
 * every assumption is a constant or a method below, to be checked against the
 * chosen provider's API reference:
 *
 *   token   POST {base}/oauth/token, OAuth2 client credentials
 *           (PEPPOL_ASP_CLIENT_ID / _SECRET), cached until it expires
 *   submit  POST {base}/documents {document: base64 XML, sender, receiver}
 *           with Idempotency-Key: the document id -> {id}
 *   status  GET {base}/documents/{id} -> {status, mls_code, errors,
 *           reporting_status, authority_reference, reporting_errors}
 *   report  POST {base}/documents/{id}/report -> 2xx: reported again
 *   find    GET {base}/documents?reference={our document id} -> {id, status} or 404
 *   hooks   WebhookSignature and WebhookPayload
 *
 * The idempotency key is what makes a retry after a lost answer safe; a
 * provider that does not honour one must be told about duplicates some other
 * way (most also refuse a second document with the same invoice number).
 *
 * Verdicts as the retired direct-to-authority client learned them: 401, 403,
 * 408, 429, a redirect and any 5xx say nothing about the document and are
 * retried; any other 4xx is the provider refusing it. Neither secrets nor
 * response bodies from the token call reach a log or an exception message.
 */
final class HttpAspGateway implements AspGateway
{
    private const TOKEN_PATH = '/oauth/token';

    private const DOCUMENTS_PATH = '/documents';

    private const CONNECT_TIMEOUT = 10;

    private const TIMEOUT = 30;

    /**
     * Statuses that are no verdict on the document.
     *
     * 409 among them: a retry carries the first attempt's idempotency key, and
     * a provider answers a key it has already seen with a conflict. Reading
     * that as a refusal marked a delivered invoice rejected and invited the
     * seller to issue it again.
     */
    private const NO_VERDICT = [401, 403, 408, 409, 425, 429];

    /**
     * Read by SafeUrl and deliberately unset: the operator chose this one
     * address, so an allowlist would only repeat it. What still applies is
     * https only, resolving to a public address, connected to as resolved.
     */
    private const HOSTS_ALLOWLIST = 'peppol.asp.allowed_hosts';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly WebhookSignature $signature,
    ) {}

    public function submit(PeppolDocument $document): string
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => (string) $document->id])
            ->post($this->url(self::DOCUMENTS_PATH), [
                'document' => base64_encode((string) $document->xml),
                'sender' => $document->sender_endpoint,
                'receiver' => $document->receiver_endpoint,
            ]));

        if (! $response->successful()) {
            $this->refuse($response);
        }

        $id = $response->json('id');

        // Accepted, but nothing to follow it by. Sending it again would file
        // it twice, so it is left in 'sending' for an operator.
        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('The service provider accepted the document without a message id.');
        }

        return (string) $id;
    }

    public function status(string $messageId): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->get($this->url(self::DOCUMENTS_PATH.'/'.rawurlencode($messageId))));

        // A failed lookup changes nothing; the previous status stands.
        if (! $response->successful()) {
            throw new AspUnavailableException("The service provider did not answer the status lookup (HTTP {$response->status()}).");
        }

        return WebhookPayload::lookupFrom($messageId, (array) $response->json());
    }

    public function findByReference(string $reference): ?AspStatus
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->get($this->url(self::DOCUMENTS_PATH), ['reference' => $reference]));

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new AspUnavailableException("The service provider did not answer the lookup by reference (HTTP {$response->status()}).");
        }

        $id = $response->json('id');

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        return WebhookPayload::statusFrom((string) $id, (array) $response->json());
    }

    public function retryReport(string $messageId): void
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->post($this->url(self::DOCUMENTS_PATH.'/'.rawurlencode($messageId).'/report')));

        if (! $response->successful()) {
            $this->refuse($response);
        }
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->signature->verify($request);
    }

    public function parseStatusWebhook(Request $request): AspStatus
    {
        return WebhookPayload::status($request);
    }

    public function parseInboundWebhook(Request $request): InboundMessage
    {
        return WebhookPayload::inbound($request);
    }

    /**
     * One authorised request, with an unreachable provider as no verdict.
     *
     * @param  callable(PendingRequest): Response  $request
     */
    private function call(callable $request): Response
    {
        try {
            $response = $request($this->client()->withToken($this->token()));
        } catch (ConnectionException) {
            throw new AspUnavailableException('The service provider could not be reached.');
        }

        // A token the provider no longer accepts is dropped, so the retry
        // asks for a new one rather than presenting it again.
        if (in_array($response->status(), [401, 403], true)) {
            Cache::forget($this->tokenKey());
        }

        return $response;
    }

    /**
     * @throws AspUnavailableException|AspRefusedException
     */
    private function refuse(Response $response): never
    {
        $status = $response->status();

        if ($status >= 500 || $response->redirect() || in_array($status, self::NO_VERDICT, true)) {
            throw new AspUnavailableException("The service provider gave no verdict (HTTP {$status}).");
        }

        throw new AspRefusedException(
            "The service provider refused the document (HTTP {$status}).",
            $this->errorsIn($response) ?: [new AspError("HTTP {$status}", (string) $status)],
        );
    }

    /**
     * The provider's errors, each with its rule, severity and field where it
     * gives them, else its message.
     *
     * @return list<AspError>
     */
    private function errorsIn(Response $response): array
    {
        $errors = AspError::listFrom((array) $response->json('errors', []), 50);

        if ($errors === [] && is_string($response->json('message'))) {
            $errors[] = new AspError(Str::limit($response->json('message'), 1000));
        }

        return $errors;
    }

    private function token(): string
    {
        $cached = Cache::get($this->tokenKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->client()->asForm()->post($this->url(self::TOKEN_PATH), [
                'grant_type' => 'client_credentials',
                'client_id' => (string) $this->clientId,
                'client_secret' => (string) $this->clientSecret,
            ]);
        } catch (ConnectionException) {
            throw new AspUnavailableException('The service provider could not be reached for a token.');
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new AspUnavailableException("The service provider issued no token (HTTP {$response->status()}).");
        }

        // A minute early, so a token is never presented as it expires.
        Cache::put($this->tokenKey(), $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    /**
     * Per provider and client, so changing either never reuses the old token.
     */
    private function tokenKey(): string
    {
        return 'peppol:asp:token:'.hash('sha256', $this->baseUrl.'|'.$this->clientId);
    }

    /**
     * Checked on every call, not once: a name's DNS can change under a
     * running worker.
     */
    private function client(): PendingRequest
    {
        [$refusal, $address] = SafeUrl::decide($this->baseUrl, self::HOSTS_ALLOWLIST);

        if ($refusal !== null) {
            throw new AspUnavailableException('The service provider URL was refused: '.$refusal);
        }

        return Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            // A redirect would take the request, and the token, somewhere
            // SafeUrl never looked.
            ->withOptions(['allow_redirects' => false, ...$this->pinned($address)]);
    }

    /**
     * Connect to the address SafeUrl approved, and only that one.
     *
     * @return array<string, mixed>
     */
    private function pinned(?string $address): array
    {
        if ($address === null) {
            return [];
        }

        $parts = parse_url($this->baseUrl);
        $target = str_contains($address, ':') ? '['.$address.']' : $address;

        return ['curl' => [CURLOPT_RESOLVE => [($parts['host'] ?? '').':'.($parts['port'] ?? 443).':'.$target]]];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Invoice\Exceptions\IdempotencyKeyReusedException;
use App\Domains\Invoice\Services\IdempotentDraft;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Models\IdempotentRequest;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * idempotent - an Idempotency-Key on a route that makes or acts, for the
 * routes whose records do not carry the key themselves as an invoice does
 * (IdempotentDraft): making a schedule, a webhook or a schedule's period
 * again, rotating a webhook's secret, settling a split sale.
 *
 * The same semantics as an invoice's key, so a key means one thing wherever
 * it is sent:
 *
 *   same key, same request   the first answer, as it was, with
 *                            Idempotent-Replayed: true; nothing is done again
 *   same key, other request  422 IDEMPOTENCY_KEY_REUSED, nothing done
 *   while the first runs     409 IDEMPOTENCY_KEY_IN_PROGRESS, to retry later
 *
 * "Same request" is the method, the path and a SHA-256 of the body as sent
 * with its object keys sorted (IdempotentDraft::fingerprint). Only an answer
 * that succeeded is kept: a refused or failed request made nothing, so it
 * may be corrected and sent again under the same key. No key, no change.
 */
class Idempotent
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED = 'Idempotent-Replayed';

    public function __construct(
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER) ?: null;
        $organizationId = $this->tenant->getOrganizationId();

        if (! is_string($key) || $organizationId === null) {
            return $next($request);
        }

        if (mb_strlen($key) > IdempotentDraft::MAX_KEY_LENGTH) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('The Idempotency-Key header may be at most :max characters.', ['max' => IdempotentDraft::MAX_KEY_LENGTH]),
            ]);
        }

        $hash = IdempotentDraft::fingerprint([
            'method' => $request->method(),
            'path' => $request->path(),
            'body' => IdempotentDraft::sent($request),
        ]);

        try {
            $record = IdempotentRequest::query()->create([
                'org_id' => $organizationId,
                'idempotency_key' => $key,
                'request_hash' => $hash,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->earlier((string) $organizationId, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $record->delete();

            throw $e;
        }

        if ($response->isSuccessful()) {
            $record->update(['status' => $response->getStatusCode(), 'response' => (string) $response->getContent()]);
        } else {
            $record->delete();
        }

        return $response;
    }

    private function earlier(string $organizationId, string $key, string $hash): Response
    {
        $earlier = IdempotentRequest::query()
            ->where('org_id', $organizationId)
            ->where('idempotency_key', $key)
            ->first();

        // Removed between the insert and this read: the first request
        // failed, so this one is answered as a request to try again.
        if ($earlier === null) {
            return ApiResponse::error(__('A request with this Idempotency-Key is still being answered. Send it again shortly.'), 409, [], 'IDEMPOTENCY_KEY_IN_PROGRESS');
        }

        if (! hash_equals($earlier->request_hash, $hash)) {
            throw IdempotencyKeyReusedException::forRequest();
        }

        if ($earlier->status === null) {
            return ApiResponse::error(__('A request with this Idempotency-Key is still being answered. Send it again shortly.'), 409, [], 'IDEMPOTENCY_KEY_IN_PROGRESS');
        }

        return response((string) $earlier->response, $earlier->status, [
            'Content-Type' => 'application/json',
            self::REPLAYED => 'true',
        ]);
    }
}

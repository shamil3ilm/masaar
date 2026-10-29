<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Invoice\Exceptions\IdempotencyKeyReusedException;
use App\Domains\Invoice\Services\IdempotentDraft;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Models\IdempotentRequest;
use App\Http\Responses\ApiResponse;
use App\Support\Errors\ErrorCode;
use Closure;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * idempotent - an Idempotency-Key on a route that makes or acts, for the
 * routes whose records do not carry the key themselves as an invoice does
 * (IdempotentDraft): making a schedule, a webhook or a schedule's period
 * again, rotating a webhook's secret, selling or using a voucher, a
 * contract's invoices, a delivery note or a summary, settling a split sale.
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
 * may be corrected and sent again under the same key.
 *
 * That holds because the request runs in one transaction, key or none:
 * whatever it fails on - a deadlock, a lock wait, an exception, a process
 * that dies and takes its connection with it - nothing it wrote is kept.
 * The answer is kept in the same commit as the work, so a key is never left
 * free with its work done. A key whose request died answers 409 for
 * IdempotentRequest::IN_FLIGHT_MINUTES, then belongs to the next retry.
 *
 * idempotent:stepwise is for a route that files with an authority: a
 * document filed cannot be undone, so it commits as it goes. When it fails
 * having saved nothing, the key is free again; when it fails having saved
 * part of its work - a note drafted, a document issued - the key answers
 * 409 IDEMPOTENCY_KEY_INCOMPLETE from then on rather than run it again, as
 * it does for one whose process died.
 */
class Idempotent
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED = 'Idempotent-Replayed';

    public const STEPWISE = 'stepwise';

    /** Set on a request run in one transaction: its failure saved nothing (bootstrap/app.php). */
    public const ATOMIC = 'idempotent.atomic';

    /** Transactions committed back to the level a stepwise request began at (committed()). */
    private static int $commits = 0;

    private static ?int $floor = null;

    public function __construct(
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $stepwise = $mode === self::STEPWISE;
        $key = $request->header(self::HEADER) ?: null;
        $organizationId = $this->tenant->getOrganizationId();

        if (! is_string($key) || $organizationId === null) {
            return $stepwise ? $next($request) : $this->atomically($request, $next, null);
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
            return $this->earlier((string) $organizationId, $key, $hash, $request, $next, $stepwise);
        }

        return $stepwise ? $this->stepwise($record, $request, $next) : $this->atomically($request, $next, $record);
    }

    /**
     * A transaction committed: counted when it ends one a stepwise request
     * opened, so that request knows it saved something before it failed.
     * Listened for by AppServiceProvider.
     */
    public static function committed(TransactionCommitted $event): void
    {
        if (self::$floor !== null && $event->connection->transactionLevel() <= self::$floor) {
            self::$commits++;
        }
    }

    /**
     * The request in one transaction: kept with its answer when it
     * succeeds, undone whole when it does not.
     */
    private function atomically(Request $request, Closure $next, ?IdempotentRequest $record): Response
    {
        $request->attributes->set(self::ATOMIC, true);
        $level = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::rollBack($level);
            $record?->delete();

            throw $e;
        }

        if (! $response->isSuccessful()) {
            DB::rollBack($level);
            $record?->delete();

            return $response;
        }

        $record?->update(['status' => $response->getStatusCode(), 'response' => (string) $response->getContent()]);
        DB::commit();

        return $response;
    }

    private function stepwise(IdempotentRequest $record, Request $request, Closure $next): Response
    {
        $before = self::$commits;
        self::$floor = DB::transactionLevel();

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->failed($record, self::$commits !== $before);

            throw $e;
        } finally {
            self::$floor = null;
        }

        if ($response->isSuccessful()) {
            $record->update(['status' => $response->getStatusCode(), 'response' => (string) $response->getContent()]);
        } else {
            $this->failed($record, self::$commits !== $before);
        }

        return $response;
    }

    /**
     * A stepwise request that did not succeed: free to be sent again when it
     * saved nothing; answered as incomplete from now on when it saved part.
     */
    private function failed(IdempotentRequest $record, bool $savedPart): void
    {
        if (! $savedPart) {
            $record->delete();

            return;
        }

        $answer = self::incomplete();
        $record->update(['status' => $answer->getStatusCode(), 'response' => (string) $answer->getContent()]);
    }

    private function earlier(string $organizationId, string $key, string $hash, Request $request, Closure $next, bool $stepwise): Response
    {
        $earlier = IdempotentRequest::query()
            ->where('org_id', $organizationId)
            ->where('idempotency_key', $key)
            ->first();

        // Removed between the insert and this read: the first request
        // failed, so this one is answered as a request to try again.
        if ($earlier === null) {
            return self::inProgress();
        }

        if (! hash_equals($earlier->request_hash, $hash)) {
            throw IdempotencyKeyReusedException::forRequest();
        }

        if ($earlier->status === null) {
            if (! $earlier->abandoned()) {
                return self::inProgress();
            }

            // Its process died. A stepwise request may have filed something
            // first, so it is not run again; one transaction died with its
            // connection, so nothing of it was kept and this one takes over.
            if ($stepwise) {
                return self::incomplete();
            }

            return $earlier->claim() ? $this->atomically($request, $next, $earlier) : self::inProgress();
        }

        // A kept refusal is the key's answer now (failed()), not a replay
        // of what the first request answered.
        return response((string) $earlier->response, $earlier->status, [
            'Content-Type' => 'application/json',
            ...($earlier->status < 400 ? [self::REPLAYED => 'true'] : []),
        ]);
    }

    private static function inProgress(): Response
    {
        $code = ErrorCode::IDEMPOTENCY_KEY_IN_PROGRESS;

        return ApiResponse::error(__('A request with this Idempotency-Key is still being answered. Send it again shortly.'), $code->getHttpStatus(), [], $code->value, $code->getCategory());
    }

    private static function incomplete(): Response
    {
        $code = ErrorCode::IDEMPOTENCY_KEY_INCOMPLETE;

        return ApiResponse::error(__('The request first sent with this Idempotency-Key saved part of its work and did not finish, so it is not run again. Read what it made before sending what is left under a new key.'), $code->getHttpStatus(), [], $code->value, $code->getCategory());
    }
}

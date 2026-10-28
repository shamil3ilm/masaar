<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Pagination\Keyset;
use App\Support\Errors\ComplianceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The one shape every API response takes.
 *
 * Errors used to arrive in eight shapes, one per handler in bootstrap/app.php,
 * and they disagreed about which keys exist. `category` appeared on four of
 * the eight. `retryable` appeared on exactly one - the FatooraException
 * handler - so a client written against it ("if the error is retryable, try
 * again") silently never retried anything that arrived by the other seven
 * paths, including every 503 rendered from an HttpException. Extra detail
 * landed under `error.details` on one path and `error.context` on two.
 *
 * So the shape is fixed here, once, and the handlers all build through it:
 *
 *   {"success": false, "error": {
 *       "code": "...", "message": "...", "category": "...",
 *       "retryable": bool, ["retry_after": int, "max_retries": int,]
 *       ["details": {...}]
 *   }}
 *
 * `retryable` is derived from the HTTP status rather than passed in. That is
 * the point: it makes every path correct without each handler having to
 * remember, which is how seven of them came to omit it.
 *
 * Two vocabularies live in `code`, and `category` says which one is being
 * read. Compliance failures carry the ErrorCode taxonomy - VAL_025, ZATCA_003
 * - and reach here only from a FatooraException, through fromFatoora().
 * Everything else carries a platform code naming the transport failure:
 * NOT_FOUND, VALIDATION_ERROR, SERVER_ERROR. A licence endpoint has no
 * business answering an invoice error code, so the two are not merged.
 */
class ApiResponse
{
    /**
     * Statuses that invite the caller to repeat the request.
     *
     * 408 and 429 are the client-side two that mean "again, later"; the rest
     * are the gateway and availability failures. A 500 is excluded on purpose:
     * it is a defect on this side and repeating it achieves nothing.
     *
     * @var list<int>
     */
    private const RETRYABLE_STATUSES = [408, 429, 502, 503, 504];

    /**
     * Success response.
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        $response = ['success' => true];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $status);
    }

    /**
     * Created response (201).
     */
    public static function created(mixed $data, string $message): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * Paginated response.
     */
    public static function paginated(LengthAwarePaginator $paginator, ?string $message = null): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return response()->json($response);
    }

    /**
     * A page walked by cursor (Keyset): the items, and the cursor for the
     * next page, null on the last.
     *
     * @param  callable(mixed): mixed|null  $present  How each item is answered, when not as it is
     */
    public static function cursor(Keyset $page, ?callable $present = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $present === null ? $page->items : array_map($present, $page->items),
            'meta' => [
                'per_page' => $page->perPage,
                'next_cursor' => $page->nextCursor,
            ],
        ]);
    }

    /**
     * Error response.
     *
     * @param  array<string, mixed>  $details  Field errors, or the context an
     *                                         exception carried. One key, not
     *                                         the two names this used to have.
     */
    public static function error(
        string $message,
        int $status = 400,
        array $details = [],
        ?string $code = null,
        ?string $category = null,
        ?int $retryAfter = null,
        ?int $maxRetries = null,
    ): JsonResponse {
        $retryable = in_array($status, self::RETRYABLE_STATUSES, true);

        $error = [
            'code' => $code ?? self::codeForStatus($status),
            'message' => $message,
            'category' => $category ?? self::categoryForStatus($status),
            'retryable' => $retryable,
        ];

        if ($retryable && $retryAfter !== null) {
            $error['retry_after'] = $retryAfter;
        }

        if ($retryable && $maxRetries !== null) {
            $error['max_retries'] = $maxRetries;
        }

        if ($details !== []) {
            $error['details'] = $details;
        }

        $response = response()->json(['success' => false, 'error' => $error], $status);

        // A client that honours Retry-After needs it on the response, not only
        // in the body. The licence handler was the only one that set it.
        if ($retryable && $retryAfter !== null) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * A compliance failure, with the taxonomy it already carries.
     *
     * The status, the category and the retry advice all come off the
     * ErrorCode, so a caller cannot answer 422 for a code the taxonomy says
     * is a 503. ComplianceController used to catch a FatooraException and pass
     * on only getMessage() with a hardcoded 422, which threw away the code the
     * enum exists to publish.
     */
    public static function fromFatoora(ComplianceException $e): JsonResponse
    {
        $code = $e->getErrorCode();

        return self::error(
            $e->getMessage(),
            $e->getHttpStatusCode(),
            self::callerSafeContext($e->getContext()),
            $code?->value ?? 'ZATCA_ERROR',
            $e->getCategory(),
            $e->isRetryable() ? $e->getRetryDelay() : null,
            $e->isRetryable() ? $e->getMaxRetries() : null,
        );
    }

    /**
     * The part of an exception's context a caller may see.
     *
     * FatooraException context was returned here verbatim and logged at the
     * same time, with nothing between arbitrary context and a partner ERP.
     * One value in use was the on-disk path of the taxpayer's signing key.
     * There are fourteen construction sites across Submitter, SubmissionGuard,
     * KillSwitch, OfflineFallback and PipelineService, so the next key added
     * to any of them would have been published by default.
     *
     * An allow-list rather than a deny-list, for that reason: a new context
     * key is invisible to the caller until someone decides it should not be.
     * Everything dropped here is still in the log, where the operator is.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function callerSafeContext(array $context): array
    {
        return array_intersect_key($context, array_flip([
            // Identifiers the caller already has, or sent.
            'invoice_id',
            'invoice_number',
            'org_id',
            'branch_id',
            'submission_id',
            'idempotency_key',
            'icv',
            // What the authority said, which is the whole point of answering.
            'zatca_code',
            'validation_errors',
            'warnings',
            // How to proceed.
            'retry_after',
            'switch',
        ]));
    }

    /**
     * Validation error response (422).
     *
     * @param  array<string, mixed>  $errors
     */
    public static function validationError(array $errors): JsonResponse
    {
        return self::error('Validation failed', 422, $errors);
    }

    /**
     * Unauthorized response (401).
     */
    public static function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return self::error($message, 401);
    }

    /**
     * Forbidden response (403).
     */
    public static function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return self::error($message, 403);
    }

    /**
     * Not found response (404).
     */
    public static function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return self::error($message, 404);
    }

    /**
     * Map HTTP status to a platform error code.
     *
     * Every status the handlers can produce is named. The old map stopped at
     * 500 and sent everything else to 'ERROR', so a 503 rendered from an
     * HttpException described itself as 'ERROR' and told the client nothing.
     */
    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            408 => 'REQUEST_TIMEOUT',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMITED',
            500 => 'SERVER_ERROR',
            502 => 'UPSTREAM_ERROR',
            503 => 'SERVICE_UNAVAILABLE',
            504 => 'UPSTREAM_TIMEOUT',
            default => $status >= 500 ? 'SERVER_ERROR' : 'BAD_REQUEST',
        };
    }

    /**
     * The half of the response that says which vocabulary `code` is from.
     */
    private static function categoryForStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'authentication',
            $status === 422 => 'validation',
            $status === 429 => 'rate_limit',
            $status >= 500 => 'system',
            default => 'request',
        };
    }
}

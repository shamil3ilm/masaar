<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * An Idempotency-Key sent again with a different request.
 *
 * Still a validation failure, so whatever handles one handles this, but the
 * API answers it with its own code: a caller has to tell "you reused a key"
 * from "your invoice is wrong", because only the first is fixed by a new key.
 */
final class IdempotencyKeyReusedException extends ValidationException
{
    public static function for(?string $invoiceNumber): self
    {
        $message = $invoiceNumber === null
            ? 'This Idempotency-Key was already used for a different request. Send a new key for a new invoice.'
            : 'This Idempotency-Key was already used for invoice '.$invoiceNumber.'. Send a new key for a new invoice.';

        return self::withMessages(['idempotency_key' => $message]);
    }

    /** The same, for a request that makes something other than an invoice. */
    public static function forRequest(): self
    {
        return self::withMessages(['idempotency_key' => 'This Idempotency-Key was already used for a different request. Send a new key for a new request.']);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->is('v1/*')) {
            return null;
        }

        return ApiResponse::error(
            $this->getMessage(),
            422,
            $this->errors(),
            'IDEMPOTENCY_KEY_REUSED',
        );
    }
}

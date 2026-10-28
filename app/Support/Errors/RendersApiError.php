<?php

declare(strict_types=1);

namespace App\Support\Errors;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * An exception that knows its own answer to an API caller.
 *
 * bootstrap/app.php renders every one of these the same way - logged, then
 * answered in the shared error envelope - so a country's exceptions need no
 * handler of their own there, and the shared code that registers the handler
 * names no country.
 */
interface RendersApiError extends Throwable
{
    /** The answer, in the error envelope (ApiResponse::error). */
    public function apiResponse(): JsonResponse;

    /** What the log keeps of it, before it is answered. */
    public function logApiError(): void;
}

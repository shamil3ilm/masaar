<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Organization\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every log line written during a request the identity of that request.
 *
 * An unhandled exception reached the log as a message and a stack trace and
 * nothing else: no tenant, no user, no way to tie it to the submission that
 * provoked it or to any other line written while the same request was being
 * served. On a platform where one deployment serves several taxpayers, a stack
 * trace that does not say whose document broke is close to unactionable, and
 * no amount of aggregation on top fixes it - whatever the errors are shipped
 * to can only group what it is given.
 *
 * Shared through Log::shareContext, so it reaches every channel and every
 * level for the rest of the request, including the framework's own handler.
 * It carries identifiers only:
 *
 *   request_id   a correlation id, also returned as X-Request-Id so a customer
 *                reporting a problem can quote it and it can be found
 *   org_id       which taxpayer, resolved the same way the tenant scope does
 *   user_id      which credential, where there is one
 *   route        the matched route's URI, not the path, so an invoice id does
 *                not multiply into thousands of distinct values
 *
 * Deliberately nothing else. A VAT number, an invoice number or a buyer's name
 * would make the log a copy of the data it describes - see LogSanitizer, which
 * exists because that had already happened once.
 */
class LogContext
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private readonly TenantResolver $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        // An inbound id is honoured so a trace survives a gateway or a retry,
        // but it is never trusted into the log unexamined: it is an attacker
        // controlled string that would otherwise be written to every line.
        $requestId = $this->inboundId($request) ?? (string) Str::uuid();

        $request->headers->set(self::HEADER, $requestId);

        Log::shareContext(array_filter([
            'request_id' => $requestId,
            'org_id' => $this->tenant->getOrganizationId(),
            'user_id' => auth()->id(),
            'route' => $request->route()?->uri(),
        ], static fn ($value): bool => $value !== null));

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * A caller-supplied correlation id, if it is one.
     *
     * Bounded and restricted to the characters an id is made of. Without that
     * a caller chooses what goes into every log line this request writes,
     * which is log injection with extra steps.
     */
    private function inboundId(Request $request): ?string
    {
        $candidate = (string) $request->header(self::HEADER, '');

        return preg_match('/^[A-Za-z0-9._-]{8,64}$/', $candidate) === 1
            ? $candidate
            : null;
    }
}

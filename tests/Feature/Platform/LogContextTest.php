<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Platform\Http\Middleware\LogContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every log line written during a request says which request it was.
 *
 * An unhandled exception reached the log as a message and a stack trace: no
 * tenant, no user, nothing to tie it to the submission that caused it. On a
 * deployment serving several taxpayers that is close to unactionable, and
 * shipping the errors somewhere does not fix it - an aggregator can only group
 * what it is given.
 */
class LogContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_response_carries_a_request_id(): void
    {
        $response = $this->getJson('/api/health');

        $id = $response->headers->get(LogContext::HEADER);

        $this->assertNotNull($id, 'No '.LogContext::HEADER.' on the response.');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $id);
    }

    /**
     * A caller's own id is kept, so a trace survives a gateway or a retry and
     * the same work can be followed across both sides of it.
     */
    public function test_a_callers_request_id_is_kept(): void
    {
        $response = $this->withHeader(LogContext::HEADER, 'gateway-abc-123')
            ->getJson('/api/health');

        $this->assertSame('gateway-abc-123', $response->headers->get(LogContext::HEADER));
    }

    /**
     * But not any string a caller sends. This value goes into every line the
     * request writes, so a newline or a hundred kilobytes of text would be
     * writing the log for us.
     *
     * @param  string  $candidate  what the caller sent
     */
    #[DataProvider('rejected')]
    public function test_a_hostile_request_id_is_replaced(string $candidate): void
    {
        $response = $this->withHeader(LogContext::HEADER, $candidate)
            ->getJson('/api/health');

        $id = (string) $response->headers->get(LogContext::HEADER);

        $this->assertNotSame($candidate, $id, 'The caller chose what went into the log.');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejected(): array
    {
        return [
            'too short' => ['abc'],
            'too long' => [str_repeat('a', 65)],
            'a forged log line' => ["ok\n[2026-01-01] production.ERROR: forged"],
            'spaces' => ['two words'],
            'empty' => [''],
        ];
    }

    /**
     * The point of all of it: a line written while serving a request carries
     * the request's identity without the caller having to pass it along.
     */
    public function test_a_logged_line_carries_the_context(): void
    {
        $captured = [];

        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message->context;
        });

        // A matched route: an unmatched one 404s before the api middleware
        // group runs, so nothing would have shared any context.
        $this->withHeader(LogContext::HEADER, 'trace-for-the-test')
            ->getJson('/api/health');

        Log::info('something a service would log');

        $this->assertNotEmpty($captured, 'Nothing was logged to inspect.');
        $this->assertSame(
            'trace-for-the-test',
            $captured[array_key_last($captured)]['request_id'] ?? null,
            'A log line written during the request did not carry its request_id.'
        );
    }
}

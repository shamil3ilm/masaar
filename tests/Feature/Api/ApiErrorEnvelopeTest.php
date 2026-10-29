<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * An API client is answered in the error envelope whatever went wrong -
 * never one of the HTML pages a visitor gets, and never the exception's own
 * words (debug is off here, as in production).
 */
class ApiErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private const DEADLOCK = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/_errors')->group(function () {
            Route::post('/post-only', fn () => 'ok');
            Route::get('/too-large', fn () => throw new PostTooLargeException);
            Route::get('/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::get('/crash', fn () => throw new RuntimeException('Internal detail'));
            Route::get('/unauthenticated', fn () => throw new AuthenticationException('Internal detail'));
            Route::get('/deadlock', fn () => throw new QueryException('mysql', 'select 1', [], new PDOException(self::DEADLOCK)));
            // As the framework rethrows one from a nested transaction.
            Route::get('/nested-deadlock', fn () => throw new DeadlockException(self::DEADLOCK));
        });
    }

    /**
     * A transaction the database gave up on - a deadlock, a lock wait run
     * out - wrote nothing, and the same request sent again goes through. It
     * answered 500, which tells the caller it is a fault worth reporting
     * and not one worth retrying.
     */
    public function test_a_deadlock_answers_retryable(): void
    {
        foreach (['/api/_errors/deadlock', '/api/_errors/nested-deadlock'] as $uri) {
            $this->getJson($uri)
                ->assertStatus(503)
                ->assertHeader('Retry-After', (string) config('database.issuing_lock_wait'))
                ->assertJsonPath('error.code', 'SYS_008')
                ->assertJsonPath('error.retryable', true)
                ->assertDontSee('Deadlock found');
        }
    }

    public function test_each_failure_answers_the_envelope(): void
    {
        $cases = [
            ['get', '/api/_errors/no-such-thing', 404, 'NOT_FOUND'],
            ['get', '/api/_errors/post-only', 405, 'METHOD_NOT_ALLOWED'],
            ['get', '/api/_errors/too-large', 413, 'PAYLOAD_TOO_LARGE'],
            ['get', '/api/_errors/expired', 419, null],
            ['get', '/api/_errors/crash', 500, 'SERVER_ERROR'],
            ['get', '/api/_errors/unauthenticated', 401, 'UNAUTHORIZED'],
        ];

        foreach ($cases as [$method, $uri, $status, $code]) {
            $response = $this->{$method}($uri)->assertStatus($status);

            $this->assertEnvelope($response, $uri);

            if ($code !== null) {
                $response->assertJsonPath('error.code', $code);
            }
        }
    }

    public function test_crashes_keep_their_details_private(): void
    {
        $this->getJson('/api/_errors/crash')
            ->assertStatus(500)
            ->assertJsonPath('error.message', 'An unexpected error occurred')
            ->assertDontSee('Internal detail')
            ->assertDontSee('RuntimeException');
    }

    private function assertEnvelope(TestResponse $response, string $uri): void
    {
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), $uri.' answered '.$response->headers->get('Content-Type'));
        $response->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'error' => ['code', 'message', 'category', 'retryable']])
            ->assertDontSee('<html', false)
            ->assertDontSee('Internal detail');
    }
}

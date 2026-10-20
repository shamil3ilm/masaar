<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
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

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/_errors')->group(function () {
            Route::post('/post-only', fn () => 'ok');
            Route::get('/too-large', fn () => throw new PostTooLargeException);
            Route::get('/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::get('/crash', fn () => throw new RuntimeException('Internal detail'));
            Route::get('/unauthenticated', fn () => throw new AuthenticationException('Internal detail'));
        });
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

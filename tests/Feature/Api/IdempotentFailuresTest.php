<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Invoice\Services\IdempotentDraft;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Models\IdempotentRequest;
use App\Domains\Webhook\Models\Webhook;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * What a key is left saying when its request did not finish.
 *
 * A request under a key runs in one transaction, so a failure - a deadlock,
 * a lock wait, an exception, a process that died - leaves nothing behind,
 * and the retry is the first time it really runs. A route that files with
 * an authority cannot be undone that way, so it commits as it goes; when it
 * failed after saving part of its work, the key says so instead of running
 * it again.
 */
class IdempotentFailuresTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    /** @var array<string, string> */
    private array $headers;

    /** Whether the test routes fail after their write. */
    private bool $fail = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active']);
        $issued = License::createWithCredentials(['org_id' => $this->acme->id, 'organization_name' => 'Acme', 'contact_email' => 'erp@example.test', 'tier' => 'enterprise']);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active']);
        $this->headers = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];

        // A write, and then the failure the test asks for.
        $write = function (string $failure) {
            DB::transaction(fn () => Webhook::query()->create(['org_id' => $this->acme->id, 'url' => 'https://203.0.113.20/hooks', 'secret' => 's', 'events' => ['*']]));

            if ($this->fail) {
                throw match ($failure) {
                    'deadlock' => new QueryException('mysql', 'update invoices', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')),
                    default => new RuntimeException('failed after the write'),
                };
            }

            return response()->json(['success' => true, 'data' => ['webhooks' => Webhook::query()->count()]], 201);
        };

        Route::middleware(['license', 'idempotent'])->post('/api/v1/test-atomic/{failure}', $write);
        Route::middleware(['license', 'idempotent:stepwise'])->post('/api/v1/test-stepwise/{failure}', $write);
        Route::middleware(['license'])->post('/api/v1/test-plain/{failure}', $write);
    }

    public function test_a_failure_saves_nothing(): void
    {
        $this->send('/api/v1/test-atomic/exception', 'atomic-1')->assertStatus(500);
        $this->assertSame(0, Webhook::acrossTenants()->count());

        $this->fail = false;
        $this->send('/api/v1/test-atomic/exception', 'atomic-1')->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(1, Webhook::acrossTenants()->count());
    }

    public function test_a_deadlock_saved_nothing_truly(): void
    {
        $this->send('/api/v1/test-atomic/deadlock', 'atomic-2')
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'SYS_008')
            ->assertJsonPath('error.message', 'The request met another one changing the same records and was not completed; nothing was saved. Send the same request again.');

        $this->assertSame(0, Webhook::acrossTenants()->count());
    }

    /** Where the request was not one transaction, the answer does not claim it was. */
    public function test_elsewhere_it_promises_nothing(): void
    {
        $this->send('/api/v1/test-plain/deadlock', null)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SYS_008')
            ->assertJsonPath('error.message', 'The request met another one changing the same records and did not complete. What it saved before that is kept: check it before sending the request again.');
    }

    public function test_a_partial_step_is_not_repeated(): void
    {
        $this->send('/api/v1/test-stepwise/exception', 'step-1')->assertStatus(500);

        $this->fail = false;
        $this->send('/api/v1/test-stepwise/exception', 'step-1')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INCOMPLETE')
            ->assertJsonPath('error.retryable', false)
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(1, Webhook::acrossTenants()->count(), 'the first request\'s write, once');
    }

    public function test_a_step_refused_early_may_retry(): void
    {
        Route::middleware(['license', 'idempotent:stepwise'])->post('/api/v1/test-refused', fn () => response()->json(['success' => false], 422));

        $this->send('/api/v1/test-refused', 'step-2')->assertStatus(422);

        $this->assertSame(0, IdempotentRequest::query()->count(), 'nothing saved, so the key is free');
    }

    public function test_an_abandoned_request_frees_its_key(): void
    {
        $this->fail = false;
        $this->abandoned('/api/v1/test-atomic/exception', 'died-1');

        $this->travel(1)->minutes();
        $this->send('/api/v1/test-atomic/exception', 'died-1')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_IN_PROGRESS');

        $this->travel(5)->minutes();
        $this->send('/api/v1/test-atomic/exception', 'died-1')->assertCreated();
        $this->send('/api/v1/test-atomic/exception', 'died-1')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Webhook::acrossTenants()->count());
    }

    /** A stepwise request that died may have filed something: it is not run again. */
    public function test_an_abandoned_step_is_incomplete(): void
    {
        $this->fail = false;
        $this->abandoned('/api/v1/test-stepwise/exception', 'died-2');

        $this->travel(6)->minutes();
        $this->send('/api/v1/test-stepwise/exception', 'died-2')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INCOMPLETE');
        $this->assertSame(0, Webhook::acrossTenants()->count());
    }

    private function send(string $path, ?string $key): TestResponse
    {
        return $this->postJson($path, ['n' => 1], [...$this->headers, ...($key === null ? [] : ['Idempotency-Key' => $key])]);
    }

    /** The row a request leaves when its process dies mid-flight: no answer. */
    private function abandoned(string $path, string $key): void
    {
        IdempotentRequest::query()->create([
            'org_id' => $this->acme->id,
            'idempotency_key' => $key,
            'request_hash' => IdempotentDraft::fingerprint(['method' => 'POST', 'path' => ltrim($path, '/'), 'body' => ['n' => 1]]),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainEntry;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDOException;
use Tests\TestCase;

/**
 * A till whose device is busy is told to send the request again - never a
 * 500, and never half an invoice.
 *
 * Every till without credentials of its own signs on head office's device,
 * so a busy store queues on one issuing lock. A request that could not have
 * it - MySQL's lock wait running out, or a deadlock chosen against it - fell
 * through to the catch-all handler as a 500 (docs/operations/LOAD-TEST.md),
 * and the till could not tell whether the sale was on the books.
 */
class BusyDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const LOCK_WAIT = 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction';

    private const DEADLOCK = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction';

    private Organization $acme;

    /** @var array<string, string> */
    private array $credential;

    /** What taking the device's lock fails with, while set. */
    private ?string $failure = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme', 'country' => 'SA', 'vat_number' => '300000000000003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya',
            'city' => 'Riyadh', 'postal_code' => '12345',
        ]);

        License::create([
            'org_id' => $this->acme->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret('test-secret-acme'),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@example.test',
            'environment' => 'sandbox',
            'tier' => 'starter',
            'status' => 'active',
            'scopes' => ['invoice.submit', 'compliance.status'],
        ]);

        $this->credential = ['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => 'test-secret-acme'];

        // The device's lock is the first thing issuing takes, so a failure
        // there is a request that never had the device.
        DB::connection()->beforeExecuting(function (string $query): void {
            if ($this->failure !== null && str_contains($query, 'issuing_locks')) {
                throw new QueryException('sqlite', $query, [], new PDOException($this->failure));
            }
        });
    }

    public function test_busy_device_answers_retryable(): void
    {
        $this->failure = self::LOCK_WAIT;

        $this->submit('key-1')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('error.code', 'SYS_008')
            ->assertJsonPath('error.retryable', true);

        // Drafted, and nothing more: no number from the sequence, no
        // counter, no hash, no place in the chain.
        $invoice = Invoice::acrossTenants()->sole();
        $this->assertSame('draft', $invoice->status->value);
        $this->assertNull($invoice->icv);
        $this->assertNull($invoice->hash);
        $this->assertSame(0, ChainEntry::acrossTenants()->count());
    }

    public function test_deadlock_answers_retryable_not_500(): void
    {
        $this->failure = self::DEADLOCK;

        $this->submit('key-1')->assertStatus(503)->assertJsonPath('error.code', 'SYS_008');

        $this->assertSame('draft', Invoice::acrossTenants()->sole()->status->value);
    }

    /**
     * Sent again under its key once the device is free, it issues the draft
     * the first call made - once, first in the chain, and the chain checks.
     */
    public function test_retry_after_busy_issues_once(): void
    {
        $this->failure = self::LOCK_WAIT;
        $this->submit('key-1')->assertStatus(503);

        $this->failure = null;
        $retry = $this->submit('key-1')->assertCreated();

        $this->assertSame('issued', $retry->json('data.status'));
        $this->assertSame(1, Invoice::acrossTenants()->count());
        $this->assertSame(1, (int) Invoice::acrossTenants()->sole()->icv);
        $this->assertSame(1, ChainEntry::acrossTenants()->count());
        $this->artisan('fatoora:verify-hash-chain', ['--organization' => $this->acme->id])->assertSuccessful();

        // And again: the same answer, nothing new.
        $this->submit('key-1')->assertCreated();
        $this->assertSame(1, ChainEntry::acrossTenants()->count());
    }

    /**
     * A till that resends without a key - its first answer lost to a
     * timeout - meets its own invoice number. That is a conflict to read,
     * not a crash on the unique index.
     */
    public function test_repeated_number_answers_conflict(): void
    {
        $this->submit(null)->assertCreated();

        $this->submit(null)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VAL_026');

        $this->assertSame(1, Invoice::acrossTenants()->count());
    }

    private function submit(?string $key): TestResponse
    {
        return $this->withHeaders([...$this->credential, ...($key === null ? [] : ['Idempotency-Key' => $key])])
            ->postJson('/api/v1/pipeline/submit', [
                'org_id' => $this->acme->id,
                'invoice_number' => 'INV-1',
                'type' => 'simplified',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'auto_submit' => false,
                'lines' => [[
                    'description' => 'Item',
                    'description_ar' => 'صنف',
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
            ]);
    }
}

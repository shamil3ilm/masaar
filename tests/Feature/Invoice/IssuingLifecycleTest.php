<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Contracts\Seal;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuingLifecycle;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The lifecycle every country shares, driven by a jurisdiction that only
 * records when it is called and what the invoice looked like at the time.
 */
class IssuingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
    }

    /**
     * The jurisdiction's steps run where the contract puts them: before the
     * number, after the number and moment of issue, after the save, and
     * after the transaction.
     */
    public function test_hooks_run_in_lifecycle_order(): void
    {
        $fake = new RecordingJurisdiction;
        $outside = DB::transactionLevel();

        $result = $this->lifecycle()->issue($this->draft(), $this->acme, $fake);

        $this->assertSame('issued', $result);
        $this->assertSame(['prepare', 'beforeNumbering', 'seal', 'afterIssued', 'afterCommit'], array_column($fake->calls, 'hook'));

        [$prepare, $before, $seal, $after, $commit] = $fake->calls;

        $this->assertSame($outside, $prepare['level'], 'prepare ran inside the transaction');
        $this->assertNull($before['number'], 'numbered before beforeNumbering');
        $this->assertGreaterThan($outside, $before['level']);
        $this->assertNotNull($seal['number'], 'sealed before the number was set');
        $this->assertNotNull($seal['issued_at'], 'sealed before the moment of issue was set');
        $this->assertSame(InvoiceStatus::Draft, $seal['stored_status']);
        $this->assertSame(InvoiceStatus::Issued, $after['stored_status'], 'afterIssued ran before the save');
        $this->assertGreaterThan($outside, $after['level']);
        $this->assertSame($outside, $commit['level'], 'afterCommit ran inside the transaction');
        $this->assertTrue($commit['issued_now']);
    }

    /**
     * Seal columns are saved in the one update that makes the invoice Issued.
     */
    public function test_seal_columns_saved_with_status(): void
    {
        $draft = $this->draft();

        $this->lifecycle()->issue($draft, $this->acme, new RecordingJurisdiction(columns: ['hash' => 'sealed-hash']));

        $issued = $draft->fresh();
        $this->assertSame(InvoiceStatus::Issued, $issued->status);
        $this->assertSame('sealed-hash', $issued->hash);
        $this->assertNotNull($issued->invoice_number);
        $this->assertNotNull($issued->issued_at);
    }

    /**
     * Who issued it is kept with it, so a list can be narrowed to one
     * person's invoices; nobody, when nobody was signed in.
     */
    public function test_the_issuer_is_recorded(): void
    {
        $cashier = User::factory()->create();
        $mine = $this->draft();
        $theirs = $this->draft();

        $this->actingAs($cashier);
        $this->lifecycle()->issue($mine, $this->acme, new RecordingJurisdiction);
        auth()->logout();
        $this->lifecycle()->issue($theirs, $this->acme, new RecordingJurisdiction);

        $this->assertSame($cashier->id, $mine->fresh()->issued_by);
        $this->assertNull($theirs->fresh()->issued_by);
    }

    /**
     * The chain's lock is taken inside the transaction before the invoice is
     * refreshed, and the draft check is made again on the refreshed row:
     * a copy loaded before another request issued it is refused under the
     * lock, without numbering or sealing anything.
     */
    public function test_stale_copy_refused_under_lock(): void
    {
        $draft = $this->draft();
        $stale = Invoice::acrossTenants()->find($draft->id);

        $this->lifecycle()->issue($draft, $this->acme, new RecordingJurisdiction);
        $number = $draft->fresh()->invoice_number;

        $outside = DB::transactionLevel();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        });

        $fake = new RecordingJurisdiction;

        try {
            $this->lifecycle()->issue($stale, $this->acme, $fake);
            $this->fail('A stale copy issued the invoice again.');
        } catch (RuntimeException $e) {
            $this->assertSame('issued meanwhile', $e->getMessage());
        }

        $this->assertSame(['prepare'], array_column($fake->calls, 'hook'));
        $this->assertSame($number, $draft->fresh()->invoice_number);

        // Either quoting: SQLite writes "issuing_locks", MySQL `issuing_locks`.
        $names = fn (string $table): string => '/["`]'.$table.'["`]/';
        $lock = array_search(true, array_map(fn (array $q): bool => preg_match($names('issuing_locks'), $q['sql']) === 1, $queries), true);
        $this->assertNotFalse($lock, 'the issuing lock was not taken');
        $this->assertGreaterThan($outside, $queries[$lock]['level'], 'the issuing lock was taken outside the transaction');
        $this->assertMatchesRegularExpression($names('invoices'), $queries[$lock + 1]['sql'] ?? '', 'the invoice was not refreshed after the lock');
    }

    /**
     * The lock is the chain the jurisdiction names, one row per scope within
     * the organization: two scopes are two locks.
     */
    public function test_lock_is_the_jurisdictions_scope(): void
    {
        $this->lifecycle()->issue($this->draft(), $this->acme, new RecordingJurisdiction(scope: 'till-1'));
        $this->lifecycle()->issue($this->draft(), $this->acme, new RecordingJurisdiction(scope: 'till-2'));
        $this->lifecycle()->issue($this->draft(), $this->acme, new RecordingJurisdiction(scope: 'till-1'));

        $this->assertSame(
            ['till-1', 'till-2'],
            DB::table('issuing_locks')->where('org_id', $this->acme->id)->orderBy('scope')->pluck('scope')->all(),
        );
    }

    /**
     * A non-draft without reissue is refused before the jurisdiction does
     * anything.
     */
    public function test_issued_invoice_refused_up_front(): void
    {
        $draft = $this->draft();
        $this->lifecycle()->issue($draft, $this->acme, new RecordingJurisdiction);
        $fake = new RecordingJurisdiction;

        $this->expectExceptionMessage('already issued');

        try {
            $this->lifecycle()->issue($draft->fresh(), $this->acme, $fake);
        } finally {
            $this->assertSame([], $fake->calls);
        }
    }

    /**
     * Finishing an issuance another request finished answers with what the
     * jurisdiction says it produced, and numbers, seals and saves nothing.
     */
    public function test_finished_reissue_returns_existing_data(): void
    {
        $draft = $this->draft();
        $this->lifecycle()->issue($draft, $this->acme, new RecordingJurisdiction);
        $issued = $draft->fresh();
        $fake = new RecordingJurisdiction(finished: ['hash' => 'existing']);

        $result = $this->lifecycle()->issue($issued, $this->acme, $fake, reissue: true);

        $this->assertSame(['hash' => 'existing'], $result);
        $this->assertSame(['prepare', 'finished', 'afterCommit'], array_column($fake->calls, 'hook'));
        $this->assertFalse($fake->calls[2]['issued_now']);
        $this->assertEquals($issued->updated_at, $draft->fresh()->updated_at);
    }

    /**
     * A reissue the jurisdiction says is unfinished runs the steps again,
     * keeping the number and moment the invoice already has.
     */
    public function test_unfinished_reissue_is_completed(): void
    {
        $draft = $this->draft();
        $this->lifecycle()->issue($draft, $this->acme, new RecordingJurisdiction);
        $issued = $draft->fresh();
        $fake = new RecordingJurisdiction;

        $this->lifecycle()->issue($issued, $this->acme, $fake, reissue: true);

        $this->assertSame(['prepare', 'finished', 'beforeNumbering', 'seal', 'afterIssued', 'afterCommit'], array_column($fake->calls, 'hook'));
        $this->assertSame($issued->invoice_number, $draft->fresh()->invoice_number);
        $this->assertEquals($issued->issued_at, $draft->fresh()->issued_at);
    }

    /**
     * A refused pre-check leaves a draft with no number and no moment of
     * issue, and nothing after it runs.
     */
    public function test_refused_precheck_leaves_unnumbered_draft(): void
    {
        $draft = $this->draft();
        $fake = new RecordingJurisdiction(refuse: true);

        try {
            $this->lifecycle()->issue($draft, $this->acme, $fake);
            $this->fail('The refusal did not stop issuance.');
        } catch (RuntimeException $e) {
            $this->assertSame('refused: missing buyer', $e->getMessage());
        }

        $this->assertSame(['prepare'], array_column($fake->calls, 'hook'));
        $stored = $draft->fresh();
        $this->assertSame(InvoiceStatus::Draft, $stored->status);
        $this->assertNull($stored->invoice_number);
        $this->assertNull($stored->issued_at);
    }

    /**
     * A failure in the seal rolls back the number and leaves a draft.
     */
    public function test_seal_failure_rolls_back_number(): void
    {
        $draft = $this->draft();

        try {
            $this->lifecycle()->issue($draft, $this->acme, new RecordingJurisdiction(failSeal: true));
            $this->fail('The seal failure was swallowed.');
        } catch (RuntimeException) {
            // Expected.
        }

        $stored = $draft->fresh();
        $this->assertSame(InvoiceStatus::Draft, $stored->status);
        $this->assertNull($stored->invoice_number);
    }

    /**
     * The issuance and its audit entry are one change: an entry that cannot
     * be written leaves the draft as it was, so no document is issued off
     * the record.
     */
    public function test_issuance_and_audit_commit_together(): void
    {
        $draft = $this->draft();

        $this->mock(AuditService::class, function ($mock): void {
            $mock->shouldReceive('logFor')->once()->andThrow(new RuntimeException('audit unavailable'));
        });

        try {
            $this->lifecycle()->issue($draft, $this->acme, new RecordingJurisdiction);
            $this->fail('The audit failure was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        }

        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
    }

    public function test_the_issuance_is_audited(): void
    {
        $draft = $this->draft();

        $this->lifecycle()->issue($draft, $this->acme, new RecordingJurisdiction);

        $this->assertSame(1, AuditLog::query()->where('action', 'invoice.issued')->where('entity_id', $draft->id)->count());
    }

    private function lifecycle(): IssuingLifecycle
    {
        return app(IssuingLifecycle::class);
    }

    private function draft(): Invoice
    {
        return Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);
    }
}

/**
 * Records each hook with the transaction depth and the invoice's state at the
 * moment it ran.
 */
class RecordingJurisdiction implements IssuingJurisdiction
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $columns
     */
    public function __construct(
        private readonly array $columns = [],
        private readonly ?array $finished = null,
        private readonly bool $refuse = false,
        private readonly bool $failSeal = false,
        private readonly string $scope = 'device-1',
    ) {}

    public function refuseIssued(Invoice $invoice): Throwable
    {
        return new RuntimeException('already issued');
    }

    public function refuseIssuedMeanwhile(Invoice $invoice): Throwable
    {
        return new RuntimeException('issued meanwhile');
    }

    public function prepare(Invoice $invoice, Organization $organization, bool $reissue): mixed
    {
        $this->record('prepare', $invoice);

        if ($this->refuse) {
            throw new RuntimeException('refused: missing buyer');
        }

        return 'context';
    }

    public function lockScope(Invoice $invoice, mixed $context): string
    {
        return $this->scope;
    }

    public function finished(Invoice $invoice): mixed
    {
        $this->record('finished', $invoice);

        return $this->finished;
    }

    public function beforeNumbering(Invoice $invoice, mixed $context): void
    {
        $this->record('beforeNumbering', $invoice);
    }

    public function seal(Invoice $invoice, Organization $organization, mixed $context): Seal
    {
        $this->record('seal', $invoice);

        if ($this->failSeal) {
            throw new RuntimeException('signing failed');
        }

        return new Seal($this->columns);
    }

    public function afterIssued(Invoice $invoice, mixed $context, Seal $seal): mixed
    {
        $this->record('afterIssued', $invoice);

        return 'issued';
    }

    public function afterCommit(Invoice $invoice, mixed $result, bool $issuedNow): void
    {
        $this->record('afterCommit', $invoice, ['issued_now' => $issuedNow]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(string $hook, Invoice $invoice, array $extra = []): void
    {
        $this->calls[] = [
            'hook' => $hook,
            'level' => DB::transactionLevel(),
            'number' => $invoice->invoice_number,
            'issued_at' => $invoice->issued_at,
            'stored_status' => Invoice::acrossTenants()->whereKey($invoice->id)->value('status'),
            ...$extra,
        ];
    }
}

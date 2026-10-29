<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Services\AuditChain;
use App\Domains\Audit\Services\AuditService;
use App\Domains\Audit\Services\ImmutabilityTriggers;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The audit log is a hash chain per organization (AuditChain), and
 * `platform:verify-audit-chain` finds an entry changed, removed or cut off
 * below the application. On MySQL the database refuses the change outright
 * (migration 1817); on SQLite, which has no such trigger, the chain is what
 * shows it.
 */
class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_entries_chain_to_their_predecessor(): void
    {
        [$first, $second] = [$this->entry('one', ['amount' => 1.5, 'b' => ['z' => 1, 'a' => 'ب']]), $this->entry('two')];

        $chain = AuditChain::chainOf((string) $this->acme->id);
        $rows = DB::table('audit_logs')->where('chain', $chain)->orderBy('chain_seq')->get();

        $this->assertSame([1, 2], $rows->pluck('chain_seq')->map(fn ($seq) => (int) $seq)->all());
        $this->assertSame(AuditChain::GENESIS, $rows[0]->previous_entry_hash);
        $this->assertSame($rows[0]->entry_hash, $rows[1]->previous_entry_hash);
        $this->assertSame($first->entry_hash, $rows[0]->entry_hash);
        $this->assertSame($second->entry_hash, $rows[1]->entry_hash);

        $this->artisan('platform:verify-audit-chain')->assertSuccessful();
    }

    public function test_each_organization_keeps_its_chain(): void
    {
        $other = Organization::create(['name' => 'Other', 'country' => 'SA']);

        $this->entry('acme');
        $theirs = app(AuditService::class)->logFor((string) $other->id, 'other.thing', $other);

        $this->assertSame(1, (int) $theirs->chain_seq);
        $this->assertSame(AuditChain::GENESIS, $theirs->previous_entry_hash);
        $this->artisan('platform:verify-audit-chain', ['--organization' => $other->id])->assertSuccessful();
    }

    public function test_a_changed_entry_is_found(): void
    {
        $this->skipOnMysql();
        $entry = $this->entry('one');
        $this->entry('two');

        DB::table('audit_logs')->where('id', $entry->id)->update(['action' => 'something.else']);

        $this->artisan('platform:verify-audit-chain')
            ->expectsOutputToContain('was changed after it was written')
            ->assertFailed();
    }

    public function test_a_removed_entry_is_found(): void
    {
        $this->skipOnMysql();
        $this->entry('one');
        $middle = $this->entry('two');
        $last = $this->entry('three');

        DB::table('audit_logs')->where('id', $middle->id)->delete();
        $this->artisan('platform:verify-audit-chain')->expectsOutputToContain('entries are missing')->assertFailed();

        DB::table('audit_logs')->where('id', $last->id)->delete();
        $this->artisan('platform:verify-audit-chain')->expectsOutputToContain('removed from the end')->assertFailed();
    }

    public function test_the_database_refuses_the_change(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The audit log triggers exist on MySQL only.');
        }

        $entry = $this->entry('one');

        foreach ([fn () => DB::table('audit_logs')->where('id', $entry->id)->update(['action' => 'x']), fn () => DB::table('audit_logs')->where('id', $entry->id)->delete()] as $write) {
            try {
                $write();
                $this->fail('The database allowed an audit entry to change.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('45000', $e->getMessage());
            }
        }
    }

    public function test_the_model_refuses_the_change(): void
    {
        $entry = $this->entry('one');

        $this->expectException(\RuntimeException::class);

        $entry->update(['action' => 'x']);
    }

    /**
     * A chain's head is created or locked by an upsert, first - for the
     * chain's first entry and for every one after.
     *
     * It was an INSERT IGNORE and then a locking read. On MySQL the ignored
     * duplicate takes a shared lock on the row, so two entries at once each
     * held one and each waited for the other's to lock it: a deadlock,
     * answered 500, whenever two requests of one organization wrote to its
     * audit log together (docs/operations/LOAD-TEST.md). Reading first
     * deadlocks a new chain instead, on the gap both reads lock.
     */
    public function test_head_is_upserted_first(): void
    {
        foreach (['one', 'two'] as $what) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->entry($what);

            $heads = array_values(array_filter(
                array_map('strtolower', array_column(DB::getQueryLog(), 'query')),
                fn (string $query): bool => str_contains($query, 'audit_chain_heads'),
            ));

            $this->assertStringStartsWith('insert into', $heads[0], $what);
            $this->assertMatchesRegularExpression('/on duplicate key update|on conflict/', $heads[0], $what);
            $this->assertStringNotContainsString('ignore', implode("\n", $heads), $what);
        }

        $this->artisan('platform:verify-audit-chain')->assertSuccessful();
    }

    /**
     * N2-6: with a chain key, each new entry and its chain's head are sealed
     * with a keyed hash. Someone with the database and not the key can
     * rewrite an entry and recompute its plain hash and the head, and the
     * seal still gives it away.
     */
    public function test_a_rewrite_without_the_key_is_found(): void
    {
        $this->skipOnMysql();
        config(['audit.chain_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->entry('one');
        $last = $this->entry('two');

        $this->assertNotNull($last->entry_mac);
        $this->artisan('platform:verify-audit-chain')->assertSuccessful();

        $row = (array) DB::table('audit_logs')->where('id', $last->id)->first();
        $forged = AuditChain::digest([...$row, 'action' => 'test.forged'], (string) $row['previous_entry_hash']);
        DB::table('audit_logs')->where('id', $last->id)->update(['action' => 'test.forged', 'entry_hash' => $forged]);
        DB::table('audit_chain_heads')->where('chain', $row['chain'])->update(['last_hash' => $forged]);

        $this->artisan('platform:verify-audit-chain')
            ->expectsOutputToContain('its seal does not match')
            ->assertFailed();
    }

    /** Entries written before the key still verify; an unsealed one after a sealed one does not. */
    public function test_seals_start_and_never_stop(): void
    {
        $this->skipOnMysql();
        $this->entry('before');
        config(['audit.chain_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->entry('after');

        $this->artisan('platform:verify-audit-chain')->assertSuccessful();

        config(['audit.chain_key' => null]);
        $this->entry('unsealed');

        $this->artisan('platform:verify-audit-chain')
            ->expectsOutputToContain('is not sealed')
            ->assertFailed();
    }

    /** The application key never seals the chain. */
    public function test_app_key_is_not_a_chain_key(): void
    {
        config(['audit.chain_key' => config('app.key')]);

        $this->expectException(\RuntimeException::class);

        $this->entry('one');
    }

    /** A chain's head only moves to the next entry, and is never deleted (migration 1851). */
    public function test_heads_only_move_forward(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The chain head triggers exist on MySQL only.');
        }

        $this->entry('one');
        $this->entry('two');
        $chain = AuditChain::chainOf((string) $this->acme->id);

        foreach ([fn () => DB::table('audit_chain_heads')->where('chain', $chain)->update(['last_seq' => 1]), fn () => DB::table('audit_chain_heads')->where('chain', $chain)->delete()] as $write) {
            try {
                $write();
                $this->fail('The database moved a chain head back.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('45000', $e->getMessage());
            }
        }
    }

    /** Every immutability trigger is in place on MySQL; none exists elsewhere. */
    public function test_triggers_are_all_found(): void
    {
        $missing = app(ImmutabilityTriggers::class)->missing();

        $this->assertSame(DB::connection()->getDriverName() === 'mysql' ? [] : ImmutabilityTriggers::EXPECTED, $missing);
    }

    /** @param array<string, mixed> $metadata */
    private function entry(string $what, array $metadata = []): AuditLog
    {
        return app(AuditService::class)->logFor((string) $this->acme->id, "test.{$what}", $this->acme, $metadata);
    }

    private function skipOnMysql(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL refuses the write itself (test_the_database_refuses_the_change).');
        }
    }
}

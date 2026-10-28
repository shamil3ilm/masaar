<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Services\AuditChain;
use App\Domains\Audit\Services\AuditService;
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

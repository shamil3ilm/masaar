<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a read replica would and would not be safe for here.
 *
 * config/database.php splits the mysql connection when DB_READ_HOST is set.
 * Whether that is safe rests on three things, two of them framework
 * behaviour, and all three are pinned below rather than assumed - because if
 * any of them changed, reads would quietly start landing on a replica in
 * places that cannot tolerate a stale row.
 *
 * 1. A read inside a transaction uses the write connection.
 *    Connection::getReadPdo returns getPdo() while $transactions > 0. Every
 *    locked read-modify-write in this application depends on it: the ICV
 *    allocation in Invoice::generateNextIcv, License::changeWithAudit,
 *    SubmissionLedger::claim, SubmissionTracker's guard.
 *
 * 2. lockForUpdate forces the write connection. Query\Builder::lock() sets
 *    useWritePdo, so a locking select is never answered by a replica - which
 *    would make the lock meaningless, since a replica cannot grant one.
 *
 * 3. sticky is on whenever a replica is configured. This is the one that is
 *    ours to get right, and the one the first two do not cover: read-after-
 *    write *outside* a transaction. This codebase does that deliberately -
 *    the invoice is refreshed after issuance has committed, the licence
 *    services return fresh() after changeWithAudit - and by then the
 *    transaction is closed, so only sticky keeps those reads on the primary.
 */
class ReplicaPostureTest extends TestCase
{
    private const CONFIG = __DIR__.'/../../../config/database.php';

    public function test_a_transaction_reads_from_write(): void
    {
        DB::transaction(function (): void {
            $connection = DB::connection();

            $this->assertSame(
                $connection->getPdo(),
                $connection->getReadPdo(),
                'a read inside a transaction no longer uses the write connection, so every locked '
                .'read-modify-write in this application could be answered by a lagging replica'
            );
        });
    }

    public function test_a_lock_uses_the_write_connection(): void
    {
        $this->assertTrue(
            DB::table('invoices')->lockForUpdate()->useWritePdo,
            'lockForUpdate no longer forces the write connection, so a locking select could be '
            .'answered by a replica, which cannot grant a lock'
        );
    }

    /**
     * A replica cannot be configured without sticky.
     *
     * Checked against the source rather than by rebuilding the config under a
     * different environment, because env() reads through a cached repository
     * and a test that sets DB_READ_HOST would be testing that cache.
     */
    public function test_a_configured_replica_is_sticky(): void
    {
        $source = file_get_contents(self::CONFIG);

        $this->assertNotFalse($source);

        if (! str_contains($source, "'read' => [")) {
            $this->fail('config/database.php no longer describes a read connection; this test is stale');
        }

        $this->assertStringContainsString(
            "'sticky' => true,",
            $source,
            'a read replica is configured without sticky, so a read taken after a write has committed '
            .'but outside any transaction can be answered by a replica that has not caught up'
        );
    }

    /**
     * And it stays off until someone asks for it.
     */
    public function test_a_replica_is_inert_when_unset(): void
    {
        $this->assertNull(
            config('database.connections.mysql.read'),
            'the connection is split without DB_READ_HOST being set, so every environment '
            .'opens a second connection for no reason'
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * A server with binary logging refuses a trigger from an account without
 * SUPER (MySQL error 1419), as a managed database does: the migrations that
 * create the immutability triggers (1814, 1817) say what to set rather than
 * passing the bare SQL error on. No database here: the refusal is staged.
 */
class TriggerRefusalTest extends TestCase
{
    public function test_refusal_names_the_setting_to_change(): void
    {
        foreach (['1814_issued_invoices_frozen_in_database', '1817_audit_logs_append_only_in_database'] as $file) {
            $refusal = new PDOException('General error: 1419 You do not have the SUPER privilege and binary logging is enabled');
            $refusal->errorInfo = ['HY000', 1419, 'You do not have the SUPER privilege and binary logging is enabled'];

            DB::shouldReceive('getDriverName')->andReturn('mysql');
            DB::shouldReceive('unprepared')->andThrow(new QueryException('mysql', 'CREATE TRIGGER', [], $refusal));

            try {
                (require database_path("migrations/{$file}.php"))->up();
                $this->fail("{$file} passed the refusal on unexplained.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('log_bin_trust_function_creators=1', $e->getMessage());
                $this->assertStringContainsString('DB_MIGRATION_USERNAME', $e->getMessage());
            }
        }
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The database refuses to change or delete an audit log entry, as the model
 * does (AuditLog): the solution must not allow its logs to be modified or
 * deleted (ZATCA E-Invoicing Detailed Guidelines 5.6, prohibited functions,
 * p27). A write below the model - a query-builder update, a console session -
 * is refused too; the hash chain (AuditChain) shows one that got past both.
 *
 * MySQL only. The test suite also runs on SQLite, where the model's guard is
 * the one tested; the triggers are tested on MySQL (AuditChainTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->create(<<<'SQL'
            CREATE TRIGGER audit_logs_unchanged BEFORE UPDATE ON audit_logs FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log entries cannot be changed.';
            END
            SQL);

        $this->create(<<<'SQL'
            CREATE TRIGGER audit_logs_kept BEFORE DELETE ON audit_logs FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log entries cannot be deleted.';
            END
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_unchanged');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_kept');
    }

    /**
     * Each trigger, with a refusal that says what to change. With binary
     * logging on, MySQL lets an account without SUPER create a trigger only
     * when log_bin_trust_function_creators is set (error 1419), and any
     * account needs the TRIGGER privilege on the database (1142).
     */
    private function create(string $sql): void
    {
        try {
            DB::unprepared($sql);
        } catch (QueryException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);

            if (in_array($code, [1419, 1142, 1227], true)) {
                throw new RuntimeException(
                    'The database refused to create the immutability triggers ('.$e->errorInfo[2].'). '
                    .'Either set log_bin_trust_function_creators=1 on the MySQL server (the triggers are deterministic) '
                    .'and give the migrating user the TRIGGER privilege, or run migrations as a user that has both '
                    .'through DB_MIGRATION_USERNAME and DB_MIGRATION_PASSWORD. See docs/operations/DEPLOYMENT.md.',
                    previous: $e,
                );
            }

            throw $e;
        }
    }
};

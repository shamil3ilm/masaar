<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
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

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_unchanged BEFORE UPDATE ON audit_logs FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log entries cannot be changed.';
            END
            SQL);

        DB::unprepared(<<<'SQL'
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
};

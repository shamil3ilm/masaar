<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * An audit chain's head (audit_chain_heads) only moves forward. It records
 * each chain's last entry, which is how platform:verify-audit-chain sees
 * entries cut off the end; a head that could be moved back, or deleted,
 * would hide that. So the database refuses to delete a head, to rename its
 * chain, or to move it other than to the next entry (or leave it where it
 * is, as the upsert AuditChain::append starts with does).
 *
 * TRUNCATE and DROP go around triggers: the application's database user has
 * neither right (docs/operations/DEPLOYMENT.md, database user).
 *
 * MySQL only, as 1814 and 1817; ImmutabilityTriggers checks they exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->create(<<<'SQL'
            CREATE TRIGGER audit_chain_heads_forward BEFORE UPDATE ON audit_chain_heads FOR EACH ROW
            BEGIN
                IF NEW.chain <> OLD.chain OR NOT (NEW.last_seq = OLD.last_seq OR NEW.last_seq = OLD.last_seq + 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An audit chain head only moves to the next entry.';
                END IF;
            END
            SQL);

        $this->create(<<<'SQL'
            CREATE TRIGGER audit_chain_heads_kept BEFORE DELETE ON audit_chain_heads FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit chain heads cannot be deleted.';
            END
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_chain_heads_forward');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_chain_heads_kept');
    }

    /** As 1817: a refusal to create a trigger says what to change. */
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

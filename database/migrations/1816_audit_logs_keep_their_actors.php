<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a user or an organization named on the audit log is refused,
 * rather than blanking the name on every entry.
 *
 * Both keys were nullOnDelete: removing the row rewrote each audit entry that
 * named it, below the model guard that refuses to change one - MySQL does not
 * run triggers for a foreign key's own action - and the entry no longer said
 * who acted or for whom, nor matched its hash in the chain (AuditChain).
 * Users are removed by soft delete and anonymised in place (AccountDeletion),
 * and an organization with invoices cannot be deleted (migration 0310), so
 * nothing the application does deletes either row; this makes it so for a
 * write below the application too.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> column => [key, table] */
    private const KEYS = [
        'org_id' => ['audit_logs_organization_id_foreign', 'organizations'],
        'user_id' => ['audit_logs_user_id_foreign', 'users'],
    ];

    public function up(): void
    {
        $this->replace(fn (Blueprint $table, string $column, string $key, string $on) => $table->foreign($column, $key)
            ->references('id')->on($on)->restrictOnDelete());
    }

    public function down(): void
    {
        $this->replace(fn (Blueprint $table, string $column, string $key, string $on) => $table->foreign($column, $key)
            ->references('id')->on($on)->nullOnDelete());
    }

    /**
     * Dropped by name on MySQL, by column on SQLite, which can only drop that way.
     */
    private function replace(Closure $add): void
    {
        foreach (self::KEYS as $column => [$key, $on]) {
            Schema::table('audit_logs', function (Blueprint $table) use ($add, $column, $key, $on) {
                $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? [$column] : $key);
                $add($table, $column, $key, $on);
            });
        }
    }
};

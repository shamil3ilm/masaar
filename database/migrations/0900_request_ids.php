<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The request each audit entry, submission, state change and reporting
 * attempt was made in: the X-Request-Id LogContext gives every request and
 * returns to the caller, carried into the jobs the request queues (Origin).
 * One id then finds the call, what it was refused or allowed, and the
 * filing it led to. usage_events has kept one since it was made.
 *
 * Nullable, at the end of each table, with no default: on MySQL 8 each is
 * an instant column add, and each index is built in place with writes
 * allowed. audit_logs is the largest table this touches.
 */
return new class extends Migration
{
    private const TABLES = ['audit_logs', 'invoice_submissions', 'submission_state_logs', 'reporting_attempts'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('request_id', 64)->nullable();
                $table->index('request_id', "{$name}_request_id_index");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex("{$name}_request_id_index");
                $table->dropColumn('request_id');
            });
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The columns a table's class does not allow, kept until the owner decided
 * (owner approved): a submission's deleted_at, which nothing ever wrote - a
 * compliance record is never deleted (ZATCA E-Invoicing Detailed Guidelines
 * p26, p27) - and updated_at on the append-only logs, which their models
 * never wrote and the ledger wrote equal to created_at.
 *
 * Reversible: down() puts each back, nullable, with updated_at equal to
 * created_at as it was written.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const APPEND_ONLY = [
        'audit_logs',
        'submission_state_logs',
        'license_registration_audits',
        'webhook_logs',
        'support_messages',
        'support_attachments',
    ];

    public function up(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });

        foreach (self::APPEND_ONLY as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable();
        });

        foreach (self::APPEND_ONLY as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable();
            });

            DB::table($name)->update(['updated_at' => DB::raw('created_at')]);
        }
    }
};

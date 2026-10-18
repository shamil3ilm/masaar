<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The indexes an organization's activity timeline reads: its audit entries
 * by person and by kind of action, newest first. Each leads with org_id, so
 * a filter reads a range of one tenant's entries in order. (org_id,
 * created_at) - the timeline unfiltered - was already there.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> index => columns */
    private const INDEXES = [
        'audit_logs_org_user_created_idx' => ['org_id', 'user_id', 'created_at'],
        'audit_logs_org_action_created_idx' => ['org_id', 'action', 'created_at'],
    ];

    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            foreach (self::INDEXES as $name => $columns) {
                $table->index($columns, $name);
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            foreach (array_keys(self::INDEXES) as $name) {
                $table->dropIndex($name);
            }
        });
    }
};

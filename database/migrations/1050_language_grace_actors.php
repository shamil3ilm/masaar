<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who granted an organization's language grace, as user ids: created_by and
 * updated_by (RecordsActors' columns) in place of granted_by, which held the
 * admin's email. Store user ids, never copies of an email: renaming or
 * deleting the account then changes no record.
 *
 * Each email is mapped to its account; one with no account any more leaves
 * the actor unknown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('language_graces', function (Blueprint $table) {
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        foreach (DB::table('language_graces')->whereNotNull('granted_by')->get(['id', 'granted_by']) as $grace) {
            $userId = DB::table('users')->where('email', $grace->granted_by)->value('id');

            if ($userId !== null) {
                DB::table('language_graces')->where('id', $grace->id)->update(['created_by' => $userId, 'updated_by' => $userId]);
            }
        }

        Schema::table('language_graces', function (Blueprint $table) {
            $table->dropColumn('granted_by');
        });
    }

    public function down(): void
    {
        Schema::table('language_graces', function (Blueprint $table) {
            $table->string('granted_by')->nullable();
        });

        foreach (DB::table('language_graces')->whereNotNull('updated_by')->get(['id', 'updated_by']) as $grace) {
            DB::table('language_graces')->where('id', $grace->id)
                ->update(['granted_by' => DB::table('users')->where('id', $grace->updated_by)->value('email')]);
        }

        Schema::table('language_graces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};

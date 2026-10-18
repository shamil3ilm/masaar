<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each branch's till number within its organization: 1, 2, 3 in the order
 * they were added. A till's own numbering series carries it (T01, T02), so
 * the series of two tills never give the same number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedSmallInteger('till_number')->nullable();
            $table->unique(['org_id', 'till_number'], 'branches_till_number_unique');
        });

        $next = [];

        foreach (DB::table('branches')->orderBy('created_at')->orderBy('id')->get(['id', 'org_id']) as $branch) {
            $next[$branch->org_id] = ($next[$branch->org_id] ?? 0) + 1;
            DB::table('branches')->where('id', $branch->id)->update(['till_number' => $next[$branch->org_id]]);
        }
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_till_number_unique');
            $table->dropColumn('till_number');
        });
    }
};

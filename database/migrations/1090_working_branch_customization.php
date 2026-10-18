<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The branch a person chose in the portal's switcher, out of its column on
 * the membership (0810) and into the customizations store as their own
 * customization in that organization (branches.working). A null column was
 * every branch, so it moves as no row.
 */
return new class extends Migration
{
    private const KEY = 'branches.working';

    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (DB::table('organization_user')->whereNotNull('working_branch_id')->get(['org_id', 'user_id', 'working_branch_id']) as $member) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'key' => self::KEY,
                'level' => 'user',
                'holder' => 'member:'.$member->org_id.':'.$member->user_id,
                'org_id' => $member->org_id,
                'branch_id' => null,
                'user_id' => $member->user_id,
                'value' => json_encode($member->working_branch_id),
                'locked' => false,
                'created_by' => $member->user_id,
                'updated_by' => $member->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('customizations')->insert($chunk);
        }

        Schema::table('organization_user', function (Blueprint $table) {
            // SQLite drops a key by its column only; 0810 named it.
            DB::getDriverName() === 'sqlite'
                ? $table->dropForeign(['working_branch_id'])
                : $table->dropForeign('organization_user_working_branch_foreign');
            $table->dropColumn('working_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->uuid('working_branch_id')->nullable();
            $table->foreign('working_branch_id', 'organization_user_working_branch_foreign')->references('id')->on('branches')->nullOnDelete();
        });

        foreach (DB::table('customizations')->where('key', self::KEY)->whereNull('deleted_at')->get() as $row) {
            $branchId = json_decode((string) $row->value, true);

            if (is_string($branchId) && DB::table('branches')->where('id', $branchId)->exists()) {
                DB::table('organization_user')->where('org_id', $row->org_id)->where('user_id', $row->user_id)->update(['working_branch_id' => $branchId]);
            }
        }

        DB::table('customizations')->where('key', self::KEY)->delete();
    }
};

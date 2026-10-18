<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner and accountant join the member roles (MemberRole). An organization's
 * founder is its owner from now on; one founded before has none, so its
 * longest-standing active admin becomes it - without one, nobody could give
 * or take the owner role there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owned = DB::table('organization_user')->where('role', 'owner')->pluck('org_id')->unique()->all();

        $first = DB::table('organization_user')
            ->where('role', 'admin')
            ->where('status', 'active')
            ->whereNotIn('org_id', $owned)
            ->orderBy('created_at')
            ->get(['org_id', 'user_id'])
            ->unique('org_id');

        foreach ($first as $member) {
            DB::table('organization_user')->where('org_id', $member->org_id)->where('user_id', $member->user_id)->update(['role' => 'owner']);
        }
    }

    public function down(): void
    {
        DB::table('organization_user')->where('role', 'owner')->update(['role' => 'admin']);
        DB::table('organization_user')->where('role', 'accountant')->update(['role' => 'viewer']);
    }
};

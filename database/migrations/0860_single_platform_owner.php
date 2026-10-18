<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The platform has exactly one owner (PlatformOwnership).
 *
 * Owners are the admins with the owner role, or with no role recorded, which
 * has always read as owner. The earliest-created active one stays owner -
 * with no active one, the earliest-created - and is given the role by name.
 * Every other is demoted to operations, each demotion on the security
 * record, so down() can put them back.
 */
return new class extends Migration
{
    private const ACTION = 'security.platform_owner.demoted_to_single_owner';

    public function up(): void
    {
        $owners = DB::table('users')
            ->where('is_platform_admin', true)
            ->where(fn ($query) => $query->where('platform_role', 'owner')->orWhereNull('platform_role'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'email', 'status', 'platform_role']);

        if ($owners->isEmpty()) {
            return;
        }

        $kept = $owners->firstWhere('status', 'active') ?? $owners->first();

        DB::table('users')->where('id', $kept->id)->update(['platform_role' => 'owner']);

        foreach ($owners->where('id', '!=', $kept->id) as $owner) {
            DB::table('users')->where('id', $owner->id)->update(['platform_role' => 'operations']);

            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => null,
                'user_id' => null,
                'action' => self::ACTION,
                'entity_type' => 'User',
                'entity_id' => $owner->id,
                'metadata' => json_encode(['email' => $owner->email, 'from' => $owner->platform_role ?? 'owner', 'to' => 'operations', 'owner' => $kept->id]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('audit_logs')->where('action', self::ACTION)->get(['id', 'entity_id']) as $entry) {
            DB::table('users')->where('id', $entry->entity_id)->update(['platform_role' => 'owner']);
        }
    }
};

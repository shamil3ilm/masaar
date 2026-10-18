<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Who created what was made before 0910 recorded it, where the record
 * already says:
 *
 *   invoices        the person on its Invoice.created audit entry
 *   organizations   the person on its Organization.created audit entry
 *   memberships     the person whose invitation was accepted into it, and
 *                   for an organization's founder, the founder
 *
 * Anything else stays null: unknown, not guessed. Each fill reads the
 * evidence first and writes by key, a thousand rows at a time, so no long
 * lock is held. What is left unattributed is written to the log, by table.
 *
 * Nothing to undo: 0910's down() drops the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices' => 'Invoice', 'organizations' => 'Organization'] as $table => $entity) {
            DB::table('audit_logs')
                ->where('action', $entity.'.created')
                ->whereNotNull('user_id')
                ->whereNotNull('entity_id')
                ->select(['id', 'entity_id', 'user_id'])
                ->chunkById(1000, function ($entries) use ($table): void {
                    foreach ($entries->groupBy('user_id') as $userId => $created) {
                        DB::table($table)
                            ->whereIn('id', $created->pluck('entity_id')->all())
                            ->whereNull('created_by')
                            ->update(['created_by' => $userId]);
                    }
                });
        }

        DB::table('organization_invitations')
            ->join('users', 'users.email', '=', 'organization_invitations.email')
            ->whereNotNull('organization_invitations.accepted_at')
            ->whereNotNull('organization_invitations.invited_by')
            ->select(['organization_invitations.id', 'organization_invitations.org_id', 'organization_invitations.invited_by', 'users.id as user_id'])
            ->orderBy('organization_invitations.id')
            ->chunk(1000, function ($accepted): void {
                foreach ($accepted as $invitation) {
                    DB::table('organization_user')
                        ->where('org_id', $invitation->org_id)
                        ->where('user_id', $invitation->user_id)
                        ->whereNull('created_by')
                        ->update(['created_by' => $invitation->invited_by]);
                }
            });

        DB::table('organizations')
            ->whereNotNull('created_by')
            ->select(['id', 'created_by'])
            ->orderBy('id')
            ->chunk(1000, function ($organizations): void {
                foreach ($organizations as $organization) {
                    DB::table('organization_user')
                        ->where('org_id', $organization->id)
                        ->where('user_id', $organization->created_by)
                        ->whereNull('created_by')
                        ->update(['created_by' => $organization->created_by]);
                }
            });

        $unattributed = [];

        foreach (['invoices', 'organizations', 'organization_user', 'customers', 'products', 'branches', 'webhooks', 'business_qr_codes'] as $table) {
            $unattributed[$table] = DB::table($table)->whereNull('created_by')->count();
        }

        Log::info('Actors backfilled; rows left without created_by', $unattributed);
    }

    public function down(): void
    {
        // The columns go with 0910.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What people may remove is kept when they do: customers, products, QR
 * codes, webhooks, invitations, compliance profiles, organization groups
 * and accounts. Removing one sets deleted_at and deleted_by (SoftDeletes,
 * RecordsActors); it can be brought back, and what referred to it still
 * resolves. Branches, licences and licence registrations already did.
 *
 * Archiving a customer or a product (archived_at) stays what it was - kept
 * in the list, left out of the invoice form's pickers - and is not removal.
 *
 * An account deleted before this (AccountDeletion) was anonymised and left
 * in place with status deleted: it is marked removed when it was
 * anonymised, by its owner, who is the only one who can delete it.
 *
 * Nullable columns added at the end: an instant add on MySQL 8.
 */
return new class extends Migration
{
    private const TABLES = [
        'business_qr_codes',
        'compliance_profiles',
        'customers',
        'organization_groups',
        'organization_invitations',
        'products',
        'users',
        'webhooks',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        DB::table('users')
            ->where('status', 'deleted')
            ->whereNotNull('anonymized_at')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => DB::raw('anonymized_at'), 'deleted_by' => DB::raw('id')]);
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};

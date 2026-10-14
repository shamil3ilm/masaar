<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One invoice counter and one hash chain per device.
 *
 * ZATCA keeps the ICV and the PIH chain per EGS unit. They were kept per
 * organization: unique on (org_id, icv), and one chain head per organization.
 * That held while every document was head office's, and stopped holding when
 * a branch began signing and filing as a device of its own.
 *
 * device_id names the device: the organization's own id for head office, the
 * branch's id for a branch with its own certificate. Both are UUIDs, so one
 * column distinguishes them without a nullable branch_id - which a unique
 * index would treat as distinct on every row, leaving head office's counter
 * unprotected.
 *
 * Everything issued so far was signed with head office's certificate, so it
 * is head office's: the backfill is org_id.
 *
 * The chain heads move to a new table keyed by device rather than having
 * their primary key changed in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('device_id')->nullable()->after('branch_id');
        });

        DB::table('invoices')->whereNotNull('icv')->update(['device_id' => DB::raw('org_id')]);

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_org_icv_unique');
            $table->unique(['device_id', 'icv'], 'invoices_device_icv_unique');
        });

        Schema::table('hash_chain_history', function (Blueprint $table) {
            $table->uuid('device_id')->nullable()->after('org_id');
        });

        DB::table('hash_chain_history')->update(['device_id' => DB::raw('org_id')]);

        Schema::table('hash_chain_history', function (Blueprint $table) {
            $table->dropUnique('hash_chain_history_org_icv_unique');
            $table->unique(['device_id', 'icv'], 'hash_chain_history_device_icv_unique');
        });

        Schema::create('hash_chain_heads', function (Blueprint $table) {
            $table->uuid('device_id');
            $table->uuid('org_id');
            $table->string('last_hash', 64);
            $table->unsignedBigInteger('last_icv');
            $table->uuid('last_invoice_id');
            $table->string('certificate_id', 64);
            $table->json('cert_transition')->nullable();
            $table->timestamp('updated_at');
            $table->primary(['device_id']);
            $table->index(['org_id'], 'hash_chain_heads_org_index');
            $table->foreign('org_id', 'hash_chain_heads_org_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });

        DB::table('hash_chain_heads')->insertUsing(
            ['device_id', 'org_id', 'last_hash', 'last_icv', 'last_invoice_id', 'certificate_id', 'cert_transition', 'updated_at'],
            DB::table('hash_chain_state')->select([
                'org_id', 'org_id', 'last_hash', 'last_icv', 'last_invoice_id', 'certificate_id', 'cert_transition', 'updated_at',
            ])
        );

        Schema::drop('hash_chain_state');
    }

    public function down(): void
    {
        Schema::create('hash_chain_state', function (Blueprint $table) {
            $table->uuid('org_id');
            $table->string('last_hash', 64);
            $table->unsignedBigInteger('last_icv');
            $table->uuid('last_invoice_id');
            $table->string('certificate_id', 64);
            $table->json('cert_transition')->nullable();
            $table->timestamp('updated_at');
            $table->primary(['org_id']);
            $table->foreign('org_id', 'hash_chain_state_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });

        // Only head office's heads go back: a per-organization table has no
        // place for a branch's, which is the information this migration added.
        DB::table('hash_chain_state')->insertUsing(
            ['org_id', 'last_hash', 'last_icv', 'last_invoice_id', 'certificate_id', 'cert_transition', 'updated_at'],
            DB::table('hash_chain_heads')->whereColumn('device_id', 'org_id')->select([
                'org_id', 'last_hash', 'last_icv', 'last_invoice_id', 'certificate_id', 'cert_transition', 'updated_at',
            ])
        );

        Schema::drop('hash_chain_heads');

        Schema::table('hash_chain_history', function (Blueprint $table) {
            $table->dropUnique('hash_chain_history_device_icv_unique');
            $table->unique(['org_id', 'icv'], 'hash_chain_history_org_icv_unique');
            $table->dropColumn('device_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_device_icv_unique');
            $table->unique(['org_id', 'icv'], 'invoices_org_icv_unique');
            $table->dropColumn('device_id');
        });
    }
};

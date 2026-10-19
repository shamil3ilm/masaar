<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The organization's EGS units - its tills - each with its own certificate,
 * counter and chain.
 *
 * ZATCA keeps one counter and one chain per E-Invoice Solution Unit ("The
 * E-Invoice Solution unit must not generate more than one sequence",
 * E-Invoicing Detailed Guidelines p28 and p35), and a taxpayer registers
 * every unit it issues from (p39; FAQ p59: every device issuing invoices
 * with the same VAT number is registered). A device was the organization
 * itself or one of its branches, so tills sharing a branch shared its chain.
 * A unit is its own row now, under its branch, and a branch may have several.
 *
 * The id of a unit is the device id it replaces, so nothing moves: head
 * office's unit has the organization's id and branch_id null, the way
 * invoices.device_id and the credential store already name it, and a
 * branch's first unit has the branch's id. Every chain carries on where it
 * was, under the certificate it holds, and a till added later gets an id of
 * its own. invoices.device_id then refers to its unit.
 *
 * host is where the unit's key and chain live: 'cloud', here, for now. A
 * unit on a store's edge node will say 'edge', and the rules that keep its
 * chain on that machine read this column.
 *
 * A compliance record (class c): every document a unit signed names it, so
 * it is never deleted. It is retired instead - once nothing it signed is
 * left to file - and a retired unit never issues again. Its status changes,
 * so it has updated_at; it keeps who added it, who last changed it, and who
 * retired it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egs_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            // Null: head office's own unit, which a branch with no unit of
            // its own issues on.
            $table->uuid('branch_id')->nullable();
            // A name the organization gives the till; null shows its number.
            $table->string('name', 100)->nullable();
            // Within the organization, shared with the branches' numbers so
            // two tills' numbering series never coincide. Null for head office.
            $table->unsignedInteger('till_number')->nullable();
            // The serial its certificate signing request carried, which
            // identifies it to ZATCA (the CSR's SN, 1-<solution>|2-<model>|3-<serial>).
            $table->string('egs_serial', 255)->nullable();
            // 'cloud' or, later, 'edge'.
            $table->string('host', 8)->default('cloud');
            // 'active', 'retiring', 'retired' or 'lost'.
            $table->string('status', 16)->default('active');
            // Where its own onboarding is: pending, csr_generated,
            // compliance_passed, active, suspended or revoked.
            $table->string('onboarding_status', 32)->default('pending');
            // dateTime, not timestamp: a certificate may run past 2038.
            $table->dateTime('cert_expires_at')->nullable();
            $table->dateTime('retired_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('org_id', 'egs_units_org_foreign')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id', 'egs_units_branch_foreign')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['org_id', 'till_number'], 'egs_units_org_till_unique');
            $table->unique(['org_id', 'egs_serial'], 'egs_units_org_serial_unique');
            $table->index(['org_id', 'branch_id', 'status'], 'egs_units_branch_status_index');
        });

        $this->backfill();

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('device_id')->references('id')->on('egs_units');
        });
    }

    public function down(): void
    {
        // By its column: SQLite cannot drop a foreign key by name.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
        });

        Schema::dropIfExists('egs_units');
    }

    /**
     * Every device there was becomes a unit under the id it had.
     */
    private function backfill(): void
    {
        $now = now();
        $expiries = DB::table('device_certificates')->where('status', 'active')->pluck('valid_to', 'device_id')->all();

        // Head office: every organization has one, onboarded when it is.
        foreach (DB::table('organizations')->orderBy('created_at')->get(['id', 'compliance_profile', 'created_at']) as $organization) {
            $profile = json_decode((string) $organization->compliance_profile, true);

            DB::table('egs_units')->insert([
                'id' => $organization->id,
                'org_id' => $organization->id,
                'branch_id' => null,
                'status' => 'active',
                'onboarding_status' => is_array($profile) && ! empty($profile['zatca_onboarded']) ? 'active' : 'pending',
                'cert_expires_at' => $expiries[$organization->id] ?? null,
                'host' => 'cloud',
                'created_at' => $organization->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }

        // Each branch's own: its till number, its serial and its onboarding
        // carry over. A removed branch's unit is retired, never deleted.
        foreach (DB::table('branches')->orderBy('created_at')->get() as $branch) {
            DB::table('egs_units')->insert([
                'id' => $branch->id,
                'org_id' => $branch->org_id,
                'branch_id' => $branch->id,
                'till_number' => $branch->till_number,
                'egs_serial' => $branch->device_serial,
                'status' => $branch->deleted_at === null ? 'active' : 'retired',
                'retired_at' => $branch->deleted_at,
                'onboarding_status' => $branch->onboarding_status,
                'cert_expires_at' => $branch->cert_expires_at,
                'host' => 'cloud',
                'created_by' => $branch->created_by ?? null,
                'created_at' => $branch->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }

        // A device an invoice names that was neither: none is expected, but
        // the foreign key below must hold for whatever is there, so it is
        // kept as a retired unit rather than refused.
        $orphans = DB::table('invoices')
            ->whereNotNull('device_id')
            ->whereNotIn('device_id', DB::table('egs_units')->select('id'))
            ->distinct()
            ->get(['org_id', 'device_id']);

        foreach ($orphans as $orphan) {
            DB::table('egs_units')->insertOrIgnore([
                'id' => $orphan->device_id,
                'org_id' => $orphan->org_id,
                'name' => 'Device '.Str::limit((string) $orphan->device_id, 8, ''),
                'status' => 'retired',
                'retired_at' => $now,
                'onboarding_status' => 'active',
                'host' => 'cloud',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};

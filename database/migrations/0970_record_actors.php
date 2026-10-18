<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who created, last changed and removed each record (RecordsActors), so an
 * organization's people can each be told apart in its history: the cashier
 * who drafted an invoice, the admin who changed a setting, whoever removed a
 * customer.
 *
 * An entity keeps created_by and updated_by, one people may remove also
 * deleted_by, and a compliance record created_by beside its own lifecycle
 * actors (invoices.issued_by). A table that already names an actor keeps
 * its name for it (invited_by, issued_by, opened_by, set_by, linked_by,
 * recorded_by, approved_by) rather than gaining a second column for the
 * same fact.
 *
 * User ids, never names: an account that goes is anonymised and its id
 * stays, and a key that nulls on delete keeps the record if a row is ever
 * removed outright. Nullable, so every existing row is valid as it stands;
 * 0980 fills what can be known.
 *
 * On MySQL each column is an instant add. A foreign key added with checks
 * on rebuilds the table, so checks are off while they are added - the new
 * columns are all null, so there is nothing to check - and each key's index
 * is built in place with writes allowed. invoices and audit_logs are the
 * tables large enough to schedule this for.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const ACTORS = [
        'users' => ['created_by', 'updated_by', 'deleted_by'],
        'organization_groups' => ['created_by', 'updated_by', 'deleted_by'],
        'organizations' => ['created_by', 'updated_by'],
        'organization_user' => ['created_by', 'updated_by'],
        'organization_invitations' => ['updated_by', 'deleted_by'],
        'compliance_profiles' => ['created_by', 'updated_by', 'deleted_by'],
        'branches' => ['created_by', 'updated_by', 'deleted_by'],
        'organization_feature_overrides' => ['created_by'],
        'settings' => ['created_by'],
        'themes' => ['created_by'],
        'invoices' => ['created_by'],
        'invoice_sequences' => ['created_by', 'updated_by'],
        'invoice_links' => ['updated_by'],
        'customers' => ['created_by', 'updated_by', 'deleted_by'],
        'products' => ['created_by', 'updated_by', 'deleted_by'],
        'business_qr_codes' => ['created_by', 'updated_by', 'deleted_by'],
        'webhooks' => ['created_by', 'updated_by', 'deleted_by'],
        'licenses' => ['updated_by', 'deleted_by'],
        'license_registrations' => ['updated_by', 'deleted_by'],
        'organization_licenses' => ['updated_by'],
        'support_tickets' => ['updated_by'],
    ];

    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach (self::ACTORS as $name => $columns) {
                Schema::table($name, function (Blueprint $table) use ($columns) {
                    foreach ($columns as $column) {
                        $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
                    }
                });
            }
        });
    }

    public function down(): void
    {
        foreach (self::ACTORS as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->dropConstrainedForeignId($column);
                }
            });
        }
    }
};

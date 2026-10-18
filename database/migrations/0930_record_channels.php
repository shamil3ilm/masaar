<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a record arrived (created_via: portal, admin, api, pipeline, batch,
 * import, system, edge - Channel), and the partner licence it came through
 * (license_id), for what can arrive more than one way: invoices and notes,
 * customers and products, and payments. The licence says which integration
 * acted for the customer; a payment's own license_id is the licence it pays
 * for, so a payment keeps only its channel.
 *
 * What came before is unknown and stays null, except a payment someone
 * recorded, which was recorded in the console.
 *
 * Instant column adds on MySQL 8; the licence keys are added with checks
 * off, in place (0910).
 */
return new class extends Migration
{
    private const LICENSED = ['invoices', 'customers', 'products'];

    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach (self::LICENSED as $name) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('created_via', 20)->nullable();
                    $table->foreignUuid('license_id')->nullable()->constrained('licenses')->nullOnDelete();
                });
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('created_via', 20)->nullable();
        });

        DB::table('payments')->whereNotNull('recorded_by')->update(['created_via' => 'admin']);
    }

    public function down(): void
    {
        foreach (self::LICENSED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('license_id');
                $table->dropColumn('created_via');
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
};

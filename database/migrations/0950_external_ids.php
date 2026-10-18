<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The id an ERP knows a customer or a product by, so it can keep its own
 * records and ours in step. Invoices already keep one (erp_reference_id).
 *
 * Unique per organization and source - the licence the ERP calls with - over
 * the rows not removed (0960), so a removed one's id can be used again.
 * Nullable: what was made in the portal has none. An instant add on MySQL 8.
 */
return new class extends Migration
{
    private const TABLES = ['customers', 'products'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('external_id', 191)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('external_id');
            });
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether prices include VAT and whether a second factor is required become
 * customizations whose organization level stays in its column, so null can
 * mean "as the platform". False was the only default there was, so a false
 * becomes null: the organization behaves exactly as before, and follows the
 * platform if the platform's default changes. A true was a choice and stays.
 */
return new class extends Migration
{
    private const COLUMNS = ['prices_include_vat', 'require_two_factor'];

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->boolean($column)->nullable()->default(null)->change();
            }
        });

        foreach (self::COLUMNS as $column) {
            DB::table('organizations')->where($column, false)->update([$column => null]);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            DB::table('organizations')->whereNull($column)->update([$column => false]);
        }

        Schema::table('organizations', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->boolean($column)->default(false)->nullable(false)->change();
            }
        });
    }
};

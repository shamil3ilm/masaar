<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product's VAT treatment: the tax category a line made from it starts
 * in, and the exemption or zero-rating reason from the organization's
 * country's code list - medicines zero-rated, cosmetics standard-rated on
 * one pharmacy's shelf. Only a default: a line changes it as it needs.
 *
 * Null for a product that names none, which every product written before
 * this does: its lines are standard-rated at the product's rate, or take a
 * saved customer's default, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tax_category', 2)->nullable();
            $table->string('exempt_code', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tax_category', 'exempt_code']);
        });
    }
};

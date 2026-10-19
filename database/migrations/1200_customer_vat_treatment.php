<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The zero rating a saved customer is eligible for as a buyer, where the
 * rate depends on who buys - a citizen's private healthcare or education in
 * Saudi Arabia, named with the national ID the customer already keeps
 * (buyer_id, buyer_id_scheme). A line for the customer that names no
 * treatment of its own, nor its product's, starts in it.
 *
 * Null for every customer until someone says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('exempt_code', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('exempt_code');
        });
    }
};

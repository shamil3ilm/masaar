<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a saved customer is an insurer: the buyer of the insurer's share
 * of a sale split between a patient and their insurer, invoiced on a tax
 * invoice to it as a registered buyer (InsuranceSplits). False for every
 * customer until someone says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_insurer')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_insurer');
        });
    }
};

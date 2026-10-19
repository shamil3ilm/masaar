<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the organization says it is - retail, pharmacy, school, exporter -
 * as the key of an entry in the admin-editable business types
 * (Catalogue::BUSINESS_TYPES). It sets defaults only: the invoice type a
 * form starts on, prices with VAT in them, the paper, the setup steps. It
 * never refuses a feature, so a mixed business is never locked out.
 *
 * Null for every organization until one is chosen, which changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('business_type', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('business_type');
        });
    }
};

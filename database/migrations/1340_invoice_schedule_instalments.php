<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A schedule that is an instalment plan: its template is one agreed total,
 * split over its periods (occurrences), each part an invoice or, paid ahead
 * of the supply, a prepayment invoice settled by the last (InstalmentModel).
 * Null for a schedule that bills the whole template every period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_schedules', function (Blueprint $table) {
            // invoice or prepayment.
            $table->string('instalment_model', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_schedules', function (Blueprint $table) {
            $table->dropColumn('instalment_model');
        });
    }
};

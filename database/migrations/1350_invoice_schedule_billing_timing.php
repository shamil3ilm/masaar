<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a schedule bills each period in advance, on its first day, or in
 * arrears, the day after it ends (BillingTiming). Every schedule so far
 * billed in advance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_schedules', function (Blueprint $table) {
            $table->string('bills_in', 16)->default('advance');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_schedules', function (Blueprint $table) {
            $table->dropColumn('bills_in');
        });
    }
};

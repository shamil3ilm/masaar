<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a till made the sale, as it sent it: the actual supply, which the
 * daily close and the sales reports count by. Not the moment of issue - that
 * is issued_at, when the platform generates the final invoice, as ZATCA's
 * Detailed Guidelines for E-Invoicing require (p60).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('sold_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('sold_at');
        });
    }
};

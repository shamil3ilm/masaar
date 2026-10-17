<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The terms the invoice is to be paid on, in words: "30 days net", "50% on
 * order". The Saudi document's KSA-22 and the UAE's IBT-020. 1000
 * characters, ZATCA's limit (BR-KSA-F-06-C14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_terms', 1000)->nullable()->after('payment_means_code');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_terms');
        });
    }
};

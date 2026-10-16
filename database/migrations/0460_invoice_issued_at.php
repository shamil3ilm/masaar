<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The moment an invoice was issued.
 *
 * KSA-25, the issue time, was read off created_at - when the draft was
 * started, which can be days before it was issued. The document, its QR and
 * the 24-hour reporting clock all said the invoice was issued when someone
 * began typing it. Invoices issued before this keep created_at, which is
 * what their signed documents already carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('issued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('issued_at');
        });
    }
};

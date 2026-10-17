<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which extra QR code, if any, an organization prints on its invoices, per
 * paper (InvoiceExtraQr): a paper size => one of its business QR codes, or
 * the invoice's own link. Always printed apart from the country's QR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('invoice_qr')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('invoice_qr');
        });
    }
};

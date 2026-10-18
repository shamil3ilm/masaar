<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who issued an invoice: the person signed in when it left draft, so a
 * list can be narrowed to one cashier's invoices.
 *
 * Null for what was issued before this was recorded, and for what an
 * integration or a queue issued with nobody signed in. Kept when the
 * account goes, as null: the invoice outlives the person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('issued_by')->nullable()->after('issued_at');
            $table->foreign('issued_by', 'invoices_issued_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign('invoices_issued_by_foreign');
            $table->dropColumn('issued_by');
        });
    }
};

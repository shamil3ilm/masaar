<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an organization numbers every document from one series.
 *
 * Off by default: each till (branch) numbers from a series of its own, so two
 * tills never wait for each other for the next number (InvoiceNumbering). An
 * organization that wants one run of numbers across its branches turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('shared_numbering')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('shared_numbering');
        });
    }
};

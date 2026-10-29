<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The emirate a UAE sale was made from, as it stood when the invoice was
 * issued (Cabinet Decision No. 52 of 2017 Art 72(2)): read by the VAT return
 * (SupplyEmirate), so a branch or the company moving emirate later does not
 * move sales already made. Null where none was known then: the return reads
 * today's, and lists the sale until one is given.
 *
 * A compliance record (class c): written once, at issue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_emirates', function (Blueprint $table) {
            $table->uuid('invoice_id')->primary();
            $table->uuid('org_id');
            $table->string('emirate', 3)->nullable();
            $table->date('issued_on');
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'issued_on']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_emirates');
    }
};

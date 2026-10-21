<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The suppliers an organization buys from, for its own purchase records
 * (Books): who issued each purchase invoice, their VAT number and where they
 * are, which decides whether their services are reverse charge.
 *
 * The organization's, never the platform's own suppliers (suppliers). An
 * entity people may remove (class b): removing one keeps every purchase that
 * names it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name', 200);
            $table->string('name_ar', 200)->nullable();
            $table->string('vat_number', 50)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('subdivision', 50)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 50)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'name']);
            $table->index(['org_id', 'vat_number']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_suppliers');
    }
};

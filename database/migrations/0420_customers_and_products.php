<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's customers and the things it sells.
 *
 * Every invoice typed its buyer's name, VAT number and address, and every
 * line its description and price, from nothing. A business that invoices the
 * same customers for the same things retyped them - and a VAT number retyped
 * is a VAT number mistyped, which the authority rejects after the invoice is
 * already in the chain.
 *
 * Archived rather than deleted: an invoice keeps its own copy of what it was
 * issued with, so nothing points here, but a customer is often wanted back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('vat_number', 15)->nullable();
            $table->string('street')->nullable();
            $table->string('building_number', 20)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('country_code', 2)->default('SA');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // One record per VAT number in an organization. NULLs do not
            // collide, so customers without one are not limited.
            $table->unique(['org_id', 'vat_number']);
            $table->index(['org_id', 'name']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('unit_code', 10);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'sku']);
            $table->index(['org_id', 'name']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('customers');
    }
};

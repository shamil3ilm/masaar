<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to put the Arabic the invoice is required to carry.
 *
 * Article 66(2) of the VAT Implementing Regulations requires an invoice to be
 * issued in Arabic, and ZATCA's Guideline for Tax Invoicing and Records (v3,
 * May 2026) 4.2.1 says every field must be completed in Arabic.
 *
 * It then exempts the data these columns hold. "Proper names include the names
 * of persons and places, the registered branch, and product names ... ZATCA
 * approves the use of Latin characters for writing such names as part of the
 * Arabic language requirements in the tax invoice." A seller name, a buyer
 * name and a product description are all proper names, so a Latin one is
 * compliant and these columns are not a compliance fix.
 *
 * They exist because the authority's own illustrative invoices, in the annex
 * of that guideline, print every name bilingually - "AL SALAM SUPPLIES CO. LTD
 * / شركة السلام للتوريدات المحدودة", "Item A - البند أ". A seller registered
 * under an Arabic name had nowhere to put it: the schema held one Arabic
 * column in total, branches.name_ar, and nothing used it for a document. The
 * seller name came from organizations.name and went into the signed XML and
 * tag 1 of the QR, so an Arabic-registered seller appeared on its own invoices
 * in transliteration or not at all.
 *
 * What the language requirement does reach is the rendition - the document
 * title and the field captions - and that is a template, not a column. See
 * resources/views/components/caption.blade.php.
 *
 * Nullable, because a Latin name is a complete answer and most sellers will
 * leave these empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('name_ar', 255)->nullable()->after('name');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('buyer_name_ar', 255)->nullable()->after('buyer_name');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('description_ar', 255)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('name_ar');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('buyer_name_ar');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('description_ar');
        });
    }
};

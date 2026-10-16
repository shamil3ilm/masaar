<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a UAE invoice carries that a Saudi one does not, and the Peppol
 * documents themselves.
 *
 * invoices.due_date: PINT AE asks for a payment due date (or terms), which
 * the stub used to fake from the supply date.
 *
 * invoices.jurisdiction_data: the fields only one country's document has -
 * for the UAE the eight transaction-type flags, the buyer's Peppol endpoint
 * and emirate. JSON rather than a column each, so the next country does not
 * widen every invoice row again. Part of the document: frozen at issue like
 * everything else not in Invoice::MUTABLE_AFTER_FINALIZED.
 *
 * invoice_lines.item_name: PINT AE has an item name (IBT-153) apart from the
 * description. invoice_lines.tax_category widens to two characters, because
 * the UAE's reverse-charge category is 'AE'.
 *
 * customers: the Peppol endpoint the service provider issued to that buyer -
 * stored, never derived from the TRN - and the emirate.
 *
 * peppol_documents (PeppolDocument): every document sent or received; the
 * paths that write it come with the transport. org_id restricts like
 * invoices do, since a filed document outlives its organization. invoice_id
 * has no foreign key: invoices are never deleted, and an inbound document
 * has no invoice of ours at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('due_date')->nullable();
            $table->json('jurisdiction_data')->nullable();
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('item_name')->nullable();
            $table->string('tax_category', 2)->default('S')->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('peppol_endpoint')->nullable();
            $table->string('subdivision', 100)->nullable();
        });

        Schema::create('peppol_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('invoice_id')->nullable()->index();
            $table->string('direction', 3);
            $table->char('country', 2);
            $table->string('customization_id');
            $table->string('profile_id');
            $table->string('document_type', 30);
            $table->longText('xml');
            $table->string('asp_message_id')->nullable()->unique();
            $table->string('status', 20);
            $table->string('mls_code', 20)->nullable();
            $table->json('errors')->nullable();
            $table->string('sender_endpoint')->nullable();
            $table->string('receiver_endpoint')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'direction', 'created_at']);
            $table->index('status');
            $table->foreign('org_id')->references('id')->on('organizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peppol_documents');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['peppol_endpoint', 'subdivision']);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('item_name');
            $table->char('tax_category', 1)->default('S')->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'jurisdiction_data']);
        });
    }
};

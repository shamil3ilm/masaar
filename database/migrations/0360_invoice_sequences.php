<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice numbers the platform gives, in an organization's own format.
 *
 * Every invoice number came from the caller - typed into the portal, or sent
 * by the ERP - and the one fallback was a timestamp that two requests in the
 * same second shared. Now a draft may leave it out and is numbered when it is
 * issued, from a sequence per document type (and per branch, if a branch has
 * its own): a pattern such as INV-{YYYY}-{SEQ}, zero-padded, reset yearly,
 * monthly or never.
 *
 * Numbered at issue, under the lock that allocates the ZATCA counter, so
 * issued invoices run without gaps: a draft that is discarded never had a
 * number to leave behind.
 *
 * `scope` is the branch id, or 'organization' for the organization's own:
 * a nullable branch_id cannot carry the unique index, because two NULLs are
 * not equal to a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('branch_id')->nullable();
            $table->string('scope', 36);
            $table->string('document_type', 20);
            $table->string('pattern', 60);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->string('reset', 10)->default('yearly');
            $table->unsignedBigInteger('next_number')->default(1);
            // The period next_number counts within: '2026', '2026-10', or
            // null for a sequence that never resets.
            $table->string('period', 7)->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'scope', 'document_type'], 'invoice_sequences_scope_unique');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        // A draft may now be unnumbered until it is issued. The unique index on
        // (org_id, invoice_number) still holds every number given.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');

        // The column cannot be required again while drafts lack a number, so
        // each is given one that says what it is.
        DB::table('invoices')->whereNull('invoice_number')->orderBy('id')->each(
            fn (object $invoice) => DB::table('invoices')->where('id', $invoice->id)->update(['invoice_number' => 'DRAFT-'.$invoice->id])
        );

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number', 255)->nullable(false)->change();
        });
    }
};

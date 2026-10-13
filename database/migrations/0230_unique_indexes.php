<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two rules the code states and the database was not holding.
 *
 * A uniqueness rule enforced only by reading before writing is not a rule.
 * Two concurrent requests both read, both find nothing, and both write. The
 * two below were each guarded that way, or not at all.
 *
 * invoices (org_id, invoice_number). DuplicateDetector's own docblock calls
 * this the rule - it is ZATCA's BT-1, the invoice number a taxpayer must not
 * reuse - and checkInvoiceNumber() enforced it with a SELECT whose result was
 * then cached for an hour. The schema had only a plain index on
 * invoice_number. Worse, nothing checked at creation time at all:
 * CreateInvoiceRequest has no unique rule and InvoiceDrafter::draft does not
 * look, so two POSTs with the same number both succeeded and the collision
 * surfaced later, at submission, as a validation failure on a document that
 * had already been given to someone.
 *
 * Safe to add: invoices are hard-deleted, not soft-deleted
 * (InvoiceDrafter::discard calls delete()), so discarding a draft releases
 * its number for reuse exactly as before.
 *
 * fta_submissions (invoice_id). One submission per invoice, which is what the
 * code already assumes everywhere else: retry() transitions the existing row
 * under lockForUpdate via claimRetry() rather than inserting a second one. But
 * FtaService::submit() created a row with no guard of any kind, so a
 * double-clicked button filed the same Peppol document twice with the UAE
 * authority and left two rows to reconcile. The only unique index on the table
 * was on `reference`, which the FTA assigns later and which is therefore NULL
 * for every row that has not been answered yet - and MySQL permits unlimited
 * NULLs in a unique index, so it constrained nothing at the moment it needed
 * to. The plain index on invoice_id is dropped because a unique index on the
 * same column serves every lookup it served.
 *
 * If this migration fails, the table already holds duplicates that the code
 * believed could not exist. That is the finding, not a problem with the
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['org_id', 'invoice_number'], 'invoices_org_invoice_number_unique');
        });

        Schema::table('fta_submissions', function (Blueprint $table) {
            // Added before the plain index is dropped, and not after.
            // invoice_id carries a foreign key, and MySQL requires the
            // referencing column to stay covered by an index: dropping the
            // only one first fails with errno 150, "needed in a foreign key
            // constraint". SQLite does not care, so the test suite would not
            // have found this and CI - which runs MySQL - would have.
            $table->unique(['invoice_id'], 'fta_submissions_invoice_id_unique');
        });

        Schema::table('fta_submissions', function (Blueprint $table) {
            // Redundant now: a unique index on the same single column serves
            // every lookup the plain one served, and the foreign key.
            $table->dropIndex('fta_submissions_invoice_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_org_invoice_number_unique');
        });

        // Restored in the same order for the same reason: the column keeps an
        // index covering its foreign key throughout.
        Schema::table('fta_submissions', function (Blueprint $table) {
            $table->index(['invoice_id'], 'fta_submissions_invoice_id_index');
        });

        Schema::table('fta_submissions', function (Blueprint $table) {
            $table->dropUnique('fta_submissions_invoice_id_unique');
        });
    }
};

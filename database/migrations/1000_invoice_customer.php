<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The saved customer an invoice was made for, beside the buyer it names.
 *
 * The invoice's buyer fields stay the document: what was signed and sent,
 * frozen at issue (Invoice::MUTABLE_AFTER_FINALIZED), whatever the customer
 * record says later. customer_id only says which customer that was, so a
 * customer's invoices can be found when their name or address has changed.
 *
 * Existing invoices are linked where the buyer's VAT number is one of the
 * organization's customers', the same match Catalogue::sameBuyer makes, a
 * thousand at a time by key so no long lock is held on invoices. The link is
 * not part of the signed document, so an issued invoice may gain one. On a
 * large invoices table this backfill is the slow part; schedule it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            });
        });

        DB::table('customers')
            ->whereNotNull('vat_number')
            ->select(['id', 'org_id', 'vat_number'])
            ->orderBy('id')
            ->chunk(1000, function ($customers): void {
                foreach ($customers as $customer) {
                    // Read first, then written by key: an update matching on
                    // the VAT number would lock every invoice of the
                    // organization it scanned.
                    $ids = DB::table('invoices')
                        ->where('org_id', $customer->org_id)
                        ->where('buyer_vat_number', $customer->vat_number)
                        ->whereNull('customer_id')
                        ->pluck('id');

                    foreach ($ids->chunk(1000) as $chunk) {
                        DB::table('invoices')->whereIn('id', $chunk->all())->update(['customer_id' => $customer->id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Peppol document's report to the authority, apart from its delivery.
 *
 * The sending provider reports the tax data to the authority in parallel
 * with delivering the document to the buyer's provider, and confirms each
 * on its own (UAE Electronic Invoicing Guidelines v1.1, p10 steps 4, 5 and
 * 8; p11 step 10). One status column could hold only one of them, so an
 * early report confirmation hid a later delivery and a failed report had
 * nowhere to go. status keeps the delivery; reporting_status the report.
 *
 * Existing rows: a 'reported' document is delivered or rejected by its
 * message level response where one came, else still sent; every document
 * the provider took is pending a report until it says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->string('reporting_status', 20)->nullable()->index();
            // The authority's reference for the report, as the provider relays it.
            $table->string('authority_reference')->nullable();
            $table->json('reporting_errors')->nullable();
            $table->timestamp('reporting_changed_at')->nullable();
        });

        $out = fn () => DB::table('peppol_documents')->where('direction', 'out');

        $out()->where('status', 'reported')->update([
            'reporting_status' => 'reported',
            'reporting_changed_at' => DB::raw('updated_at'),
            'status' => DB::raw("CASE WHEN mls_code IN ('AB', 'AP') THEN 'delivered' WHEN mls_code = 'RE' THEN 'rejected' ELSE 'sent' END"),
        ]);

        $out()->whereNull('reporting_status')
            ->whereNotNull('asp_message_id')
            ->whereIn('status', ['sent', 'delivered', 'rejected'])
            ->update(['reporting_status' => 'pending', 'reporting_changed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        DB::table('peppol_documents')
            ->where('direction', 'out')
            ->where('reporting_status', 'reported')
            ->whereIn('status', ['sent', 'delivered'])
            ->update(['status' => 'reported']);

        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->dropIndex(['reporting_status']);
            $table->dropColumn(['reporting_status', 'authority_reference', 'reporting_errors', 'reporting_changed_at']);
        });
    }
};

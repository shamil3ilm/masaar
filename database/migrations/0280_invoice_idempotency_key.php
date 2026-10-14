<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Idempotency-Key a pipeline request created an invoice under.
 *
 * The pipeline promised that an ERP retrying a timed-out call gets the
 * original outcome, and the key only reached the filing step: the retry
 * drafted a second invoice first. Kept on the invoice, a retry finds the one
 * the first call made. Unique per organization, so two retries racing each
 * other cannot both draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('idempotency_key', 255)->nullable()->after('erp_reference_id');
            $table->unique(['org_id', 'idempotency_key'], 'invoices_org_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_org_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

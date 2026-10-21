<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fingerprint of the request an Idempotency-Key was first sent with.
 *
 * The key alone told a retry from a new request only by the invoice number
 * or ERP reference, and neither is sent when numbering happens at issue: the
 * same key with another sale answered with the first sale. The SHA-256 of
 * the canonical request lets a retry be told from a reused key. Null on
 * invoices drafted before it, which keep the old comparison. A split sale
 * takes the same key, and keeps the same fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->char('idempotency_hash', 64)->nullable()->after('idempotency_key');
        });

        Schema::table('insurance_splits', function (Blueprint $table) {
            $table->char('idempotency_hash', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_splits', function (Blueprint $table) {
            $table->dropColumn('idempotency_hash');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('idempotency_hash');
        });
    }
};

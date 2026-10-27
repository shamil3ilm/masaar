<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Output tax taken back on a sale the customer never paid for (bad-debt
 * relief), and given back when it is paid after all: KSA VAT Implementing
 * Regulations Art 40(7)-(9); UAE Federal Decree-Law No. 8 of 2017 Art 64(1).
 *
 * A relief row holds the unpaid amount written off (in the invoice's
 * currency), the day the conditions were met, and what proves them - the
 * customer's notice, the accountant's certificate, the evidence of formal
 * recovery. A recovery row names the relief it answers and the amount paid
 * after it. A financial record (class c): written once, never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bad_debt_reliefs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('invoice_id');
            $table->string('kind', 20);
            $table->uuid('relief_id')->nullable();
            $table->date('happened_on');
            $table->decimal('amount', 14, 2);
            $table->date('written_off_on')->nullable();
            $table->date('notified_on')->nullable();
            $table->string('certificate_reference', 200)->nullable();
            $table->string('evidence_reference', 200)->nullable();
            $table->boolean('unrelated_confirmed')->default(false);
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'happened_on']);
            $table->index('invoice_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices');
            $table->foreign('relief_id')->references('id')->on('bad_debt_reliefs');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bad_debt_reliefs');
    }
};

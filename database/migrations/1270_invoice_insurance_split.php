<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The split sale an invoice bills a share of, and which: 'patient' or
 * 'insurer' for the two shares, 'rebill' for the patient's invoice for what
 * the insurer then declined. Set on the draft and frozen with the rest of
 * the document once it is issued; null on every other invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('insurance_split_id')->nullable();
            $table->string('split_role', 8)->nullable();

            $table->foreign('insurance_split_id', 'invoices_insurance_split_foreign')->references('id')->on('insurance_splits');
            $table->index(['insurance_split_id'], 'invoices_insurance_split_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign('invoices_insurance_split_foreign');
            $table->dropIndex('invoices_insurance_split_index');
            $table->dropColumn(['insurance_split_id', 'split_role']);
        });
    }
};

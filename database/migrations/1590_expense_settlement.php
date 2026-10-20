<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is settled on an expense: paid to its supplier (supplier_payments,
 * summed here so a payables list or an ageing is one query), and written
 * off - an amount the platform will not pay, with who and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_written_off', 14, 2)->default(0);
            $table->date('paid_on')->nullable();
            $table->timestamp('written_off_at')->nullable();
            $table->uuid('written_off_by')->nullable();
            $table->string('write_off_reason', 500)->nullable();

            $table->foreign('written_off_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['written_off_by']);
            $table->dropColumn(['amount_paid', 'amount_written_off', 'paid_on', 'written_off_at', 'written_off_by', 'write_off_reason']);
        });
    }
};

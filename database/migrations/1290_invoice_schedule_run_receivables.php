<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An instalment of a plan delivered up front (InstalmentModel::Delivered):
 * not an invoice but a payment due against the one invoice made on
 * delivery. amount_due is its share of that invoice's total, and paid_at
 * when it was recorded paid; who recorded it is updated_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_schedule_runs', function (Blueprint $table) {
            $table->decimal('amount_due', 15, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_schedule_runs', function (Blueprint $table) {
            $table->dropColumn(['amount_due', 'paid_at']);
        });
    }
};

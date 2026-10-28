<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vouchers an organization sells, and what was paid with them (Vouchers).
 *
 * vouchers: a code, its face value - what it can be redeemed for - the day
 * it was sold, how it was paid and when it expires. Selling one is not a
 * supply, so no tax invoice is made for it (KSA VAT Implementing
 * Regulations Art 19(1); UAE Federal Decree-Law No. 8 of 2017 Art 7(1)).
 * Its balance is the face value less its redemptions: nothing on the row
 * changes after it is sold.
 *
 * voucher_redemptions: each use of a voucher as payment of an issued
 * invoice - the supply, taxed on that invoice - with the customer payment it
 * was recorded as.
 *
 * Financial records (class c): written once, never changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('branch_id')->nullable();
            $table->string('code', 40);
            $table->decimal('face_value', 18, 2);
            $table->string('currency', 3);
            $table->date('sold_on');
            $table->date('expires_on')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->string('holder_name', 255)->nullable();
            $table->string('payment_method', 20);
            $table->string('reference', 100)->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->foreignUuid('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['org_id', 'code']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('voucher_id');
            $table->uuid('invoice_id');
            $table->uuid('invoice_payment_id');
            $table->decimal('amount', 18, 2);
            $table->date('redeemed_on');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('voucher_id');
            $table->unique('invoice_payment_id');
            $table->foreign('voucher_id')->references('id')->on('vouchers');
            $table->foreign('invoice_id')->references('id')->on('invoices');
            $table->foreign('invoice_payment_id')->references('id')->on('invoice_payments');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('vouchers');
    }
};

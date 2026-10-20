<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money a customer has to its credit with the platform, in one currency,
 * for its next invoices to take.
 *
 * source says where it came from and source_id which record: a payment
 * that was more than its invoices (overpayment, the payment), a credit
 * note that left more paid than owed (credit_note, the note's invoice), a
 * referral reward (referral, the referral), or an admin's adjustment. A
 * credit is spent by allocations from it (payment_allocations.credit_id),
 * so what is left of it is its amount less those - never a number kept
 * here to drift from them.
 *
 * A financial record (class c): never changed or deleted once granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_credits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->string('source', 20);
            $table->uuid('source_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->date('granted_on');
            // A reward can lapse; what was spent before stays spent.
            $table->date('expires_on')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'currency']);
            $table->index(['source', 'source_id']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_credits');
    }
};

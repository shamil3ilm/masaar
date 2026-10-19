<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One sale billed in two shares - the patient's and the insurer's - each its
 * own invoice (InsuranceSplits). The row is what ties them: how the sale was
 * divided, what the sale came to, the insurer's policy, member and claim
 * references, and what the insurer finally approved.
 *
 * A financial record (class c): never deleted, as the invoices it ties are
 * not. Its status changes once, when the insurer's decision is recorded, so
 * it has updated_at, and it keeps who split the sale, how the split arrived
 * and through which licence, and who recorded the decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_splits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('branch_id')->nullable();
            // The saved insurer billed, when it was one; the insurer's
            // invoice keeps its buyer details whatever happens to the record.
            $table->uuid('insurer_customer_id')->nullable();
            // 'percent', 'amount' or 'lines', and the patient's share by it.
            $table->string('method', 8);
            $table->decimal('patient_percent', 5, 2)->nullable();
            $table->decimal('patient_amount', 12, 2)->nullable();
            $table->string('currency', 3);
            // The sale before it was divided.
            $table->decimal('sale_subtotal', 12, 2);
            $table->decimal('sale_tax', 12, 2);
            $table->decimal('sale_total', 12, 2);
            $table->string('policy_number', 127)->nullable();
            $table->string('member_id', 127)->nullable();
            $table->string('claim_reference', 127)->nullable();
            // 'billed', then 'settled' once the insurer's decision is
            // recorded: what it approved, the credit note on its invoice for
            // the rest and the patient's invoice for it.
            $table->string('status', 16)->default('billed');
            $table->decimal('insurer_approved', 12, 2)->nullable();
            $table->uuid('credit_note_id')->nullable();
            $table->uuid('rebill_invoice_id')->nullable();
            // The request it was made under, so a retry finds it.
            $table->string('idempotency_key', 255)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_via', 20)->nullable();
            $table->foreignUuid('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->foreign('org_id', 'insurance_splits_org_foreign')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id', 'insurance_splits_branch_foreign')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('insurer_customer_id', 'insurance_splits_insurer_foreign')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('credit_note_id', 'insurance_splits_credit_note_foreign')->references('id')->on('invoices');
            $table->foreign('rebill_invoice_id', 'insurance_splits_rebill_foreign')->references('id')->on('invoices');
            $table->unique(['org_id', 'idempotency_key'], 'insurance_splits_org_idempotency_unique');
            $table->index(['org_id', 'insurer_customer_id', 'status'], 'insurance_splits_insurer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_splits');
    }
};

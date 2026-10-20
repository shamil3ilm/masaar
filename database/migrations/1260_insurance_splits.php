<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale an insurer pays for, wholly or in part (InsuranceSplits): who the
 * recipient is - the patient, whom the invoice names, with the amounts for
 * the account of the insurer and of the patient; or the insurer, which
 * contracted for the care and is invoiced for it (ZATCA General VAT
 * Guideline Healthcare, January 2023, §5.1-5.2 pp20-25) - the insurer's
 * references and what it finally approved, and the documents its decision
 * called for.
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
            // 'patient' or 'insurer': whom the invoice is issued to.
            $table->string('recipient', 8)->default('patient');
            // The saved insurer, when it was one, and its name and VAT number
            // as they stood: the patient's invoice names it in its note.
            $table->uuid('insurer_customer_id')->nullable();
            $table->string('insurer_name', 255);
            $table->string('insurer_vat_number', 15)->nullable();
            // The patient as the sale named them: the buyer of their own
            // invoice, and the one a declined claim is billed to.
            $table->string('patient_name', 255)->nullable();
            $table->string('patient_name_ar', 255)->nullable();
            $table->string('patient_id', 50)->nullable();
            $table->string('patient_id_scheme', 8)->nullable();
            // How the patient's share was set - 'percent', 'amount', 'lines',
            // or 'none' when the insurer is the recipient - and what it came
            // to: the amounts, with VAT, for the account of each.
            $table->string('method', 8);
            $table->decimal('patient_percent', 5, 2)->nullable();
            $table->decimal('patient_amount', 12, 2)->nullable();
            $table->decimal('patient_share', 12, 2)->default(0);
            $table->decimal('insurer_share', 12, 2)->default(0);
            // Each line's VAT treatment as the sale gave it, for the patient
            // if the insurer does not cover it: the insurer's invoice taxes
            // a citizen's healthcare as the insurer buys it.
            $table->json('line_treatments')->nullable();
            $table->string('currency', 3);
            // What the invoice came to.
            $table->decimal('sale_subtotal', 12, 2);
            $table->decimal('sale_tax', 12, 2);
            $table->decimal('sale_total', 12, 2);
            $table->string('policy_number', 127)->nullable();
            $table->string('member_id', 127)->nullable();
            $table->string('claim_reference', 127)->nullable();
            // 'billed', then 'settled' once the insurer's decision is
            // recorded: what it approved and why it did not approve the rest
            // ('not_covered' or 'price'), with the credit note on its
            // invoice and the patient's invoice that decision called for.
            $table->string('status', 16)->default('billed');
            $table->decimal('insurer_approved', 12, 2)->nullable();
            $table->string('decision', 12)->nullable();
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

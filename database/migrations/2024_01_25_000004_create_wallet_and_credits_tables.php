<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer/Supplier wallets
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('wallet_type', 20); // customer, supplier
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('balance', 15, 2)->default(0); // Current balance
            $table->decimal('credit_limit', 15, 2)->default(0); // For credit wallets
            $table->decimal('total_credits', 15, 2)->default(0); // Lifetime credits added
            $table->decimal('total_debits', 15, 2)->default(0); // Lifetime debits
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_negative')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'contact_id', 'currency_code']);
            $table->index(['organization_id', 'wallet_type']);
        });

        // Wallet transactions
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_type', 30); // credit, debit, adjustment, refund, transfer
            $table->string('reference_number', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('description');

            // Source of transaction
            $table->morphs('source'); // advance_payment, invoice, credit_note, refund, etc.

            $table->date('transaction_date');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'transaction_date']);
            $table->index(['source_type', 'source_id']);
        });

        // Advance payments (prepayments)
        Schema::create('advance_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_number', 30);
            $table->string('payment_type', 20); // customer_advance, supplier_advance

            // Contact
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('contact_name');

            // Payment details
            $table->date('payment_date');
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 10, 6)->default(1);
            $table->decimal('amount', 15, 2);
            $table->decimal('base_amount', 15, 2);
            $table->decimal('applied_amount', 15, 2)->default(0); // Amount used against invoices
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->decimal('available_amount', 15, 2); // amount - applied - refunded

            // Payment method
            $table->string('payment_method', 30);
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->string('cheque_number')->nullable();
            $table->date('cheque_date')->nullable();

            // Status
            $table->string('status', 20)->default('active'); // active, fully_applied, refunded, cancelled

            // Accounting
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'payment_type', 'status']);
            $table->index(['contact_id', 'status']);
        });

        // Advance payment applications (when applied to invoices/bills)
        Schema::create('advance_payment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advance_payment_id')->constrained()->cascadeOnDelete();
            $table->morphs('applied_to'); // invoice, bill
            $table->decimal('applied_amount', 15, 2);
            $table->date('applied_date');
            $table->foreignId('applied_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['applied_to_type', 'applied_to_id']);
        });

        // Credit notes
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('credit_note_number', 30);
            $table->string('credit_note_type', 20)->default('sales'); // sales, purchase

            // Reference
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('bill_id')->nullable();

            // Contact
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('contact_name');
            $table->string('contact_tax_number')->nullable();

            // Dates
            $table->date('credit_note_date');
            $table->date('original_invoice_date')->nullable();

            // Amounts
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 10, 6)->default(1);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->decimal('base_total', 15, 2);
            $table->decimal('applied_amount', 15, 2)->default(0);
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->decimal('available_amount', 15, 2);

            // Reason
            $table->string('reason_code', 30)->nullable(); // return, discount, error, damaged, etc.
            $table->text('reason')->nullable();

            // Status
            $table->string('status', 20)->default('draft'); // draft, approved, applied, refunded, cancelled

            // Compliance
            $table->string('compliance_status', 30)->nullable();
            $table->string('compliance_uuid')->nullable();

            // Accounting
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable();

            $table->text('notes')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'credit_note_type', 'status']);
            $table->index(['contact_id', 'status']);
            $table->index(['invoice_id']);
        });

        // Credit note line items
        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('original_invoice_line_id')->nullable(); // Link to original invoice line
            $table->text('description');
            $table->decimal('quantity', 15, 4);
            $table->foreignId('unit_id')->nullable();
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->string('tax_code', 10)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('total', 15, 2);
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->unsignedTinyInteger('line_order')->default(0);
            $table->timestamps();

            $table->index(['credit_note_id', 'line_order']);
        });

        // Credit note applications
        Schema::create('credit_note_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->morphs('applied_to'); // invoice, bill
            $table->decimal('applied_amount', 15, 2);
            $table->date('applied_date');
            $table->foreignId('applied_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['applied_to_type', 'applied_to_id']);
        });

        // Debit notes (for supplier returns)
        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('debit_note_number', 30);

            // Reference
            $table->foreignId('bill_id')->nullable();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('contact_name');

            // Dates and amounts (similar structure to credit notes)
            $table->date('debit_note_date');
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 10, 6)->default(1);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->decimal('applied_amount', 15, 2)->default(0);
            $table->decimal('available_amount', 15, 2);

            $table->string('reason_code', 30)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('draft');

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['contact_id', 'status']);
        });

        // Refunds
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('refund_number', 30);
            $table->string('refund_type', 20); // customer_refund, supplier_refund

            // Source
            $table->morphs('refundable'); // credit_note, advance_payment, wallet
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();

            // Refund details
            $table->date('refund_date');
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('amount', 15, 2);
            $table->string('refund_method', 30); // cash, bank_transfer, original_payment_method
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('reference')->nullable();

            $table->string('status', 20)->default('pending'); // pending, processed, cancelled
            $table->text('reason')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'refund_type', 'status']);
            $table->index(['contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('debit_notes');
        Schema::dropIfExists('credit_note_applications');
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('advance_payment_applications');
        Schema::dropIfExists('advance_payments');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};

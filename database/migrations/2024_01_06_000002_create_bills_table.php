<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bills (Supplier Invoices)
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Document identifiers
            $table->string('bill_number', 50); // Internal reference
            $table->string('supplier_invoice_number', 100)->nullable(); // Supplier's invoice number

            $table->enum('bill_type', [
                'standard',
                'debit_note',   // Returns to supplier
                'credit_note',  // Credit from supplier
            ])->default('standard');

            // Related documents
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('original_bill_id')->nullable()->constrained('bills')->nullOnDelete();

            // Supplier info
            $table->foreignId('supplier_id')->constrained('contacts');
            $table->string('supplier_name', 200);
            $table->string('supplier_tax_number', 50)->nullable();
            $table->text('supplier_address')->nullable();

            // Dates
            $table->date('bill_date');
            $table->date('due_date');
            $table->date('received_date')->nullable(); // Date goods/services received

            // Currency
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);

            // Amounts
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('base_total', 18, 4)->default(0);
            $table->decimal('amount_paid', 18, 4)->default(0);
            $table->decimal('amount_due', 18, 4)->default(0);

            // Status
            $table->enum('status', [
                'draft',
                'pending',    // Awaiting approval
                'approved',   // Ready for payment
                'partial',    // Partially paid
                'paid',
                'voided',
            ])->default('draft');

            // India GST specific
            $table->string('place_of_supply', 2)->nullable();
            $table->boolean('is_reverse_charge')->default(false);

            // Accounting
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'bill_number']);
            $table->index(['organization_id', 'supplier_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'due_date', 'status']);
        });

        // Bill Lines
        Schema::create('bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->text('description');

            $table->decimal('quantity', 18, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('unit_price', 18, 4);

            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);

            $table->foreignId('tax_category_id')->nullable()->constrained('tax_categories')->nullOnDelete();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->string('tax_code', 10)->nullable();

            // GST split (for India)
            $table->decimal('cgst_rate', 8, 4)->default(0);
            $table->decimal('cgst_amount', 18, 4)->default(0);
            $table->decimal('sgst_rate', 8, 4)->default(0);
            $table->decimal('sgst_amount', 18, 4)->default(0);
            $table->decimal('igst_rate', 8, 4)->default(0);
            $table->decimal('igst_amount', 18, 4)->default(0);
            $table->string('hsn_code', 20)->nullable();

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            // Accounting
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index('bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_lines');
        Schema::dropIfExists('bills');
    }
};

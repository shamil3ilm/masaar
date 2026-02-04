<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invoices
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Document identifiers
            $table->string('invoice_number', 50);
            $table->enum('invoice_type', [
                'standard',      // Regular invoice
                'simplified',    // Simplified invoice (B2C, under threshold)
                'credit_note',   // Credit note (returns, corrections)
                'debit_note',    // Debit note (additional charges)
            ])->default('standard');

            // Related documents
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoices')->nullOnDelete(); // For credit/debit notes

            // Customer info (denormalized for historical record)
            $table->foreignId('customer_id')->constrained('contacts');
            $table->string('customer_name', 200);
            $table->string('customer_email', 100)->nullable();
            $table->string('customer_tax_number', 50)->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();

            // Dates
            $table->date('invoice_date');
            $table->date('due_date');

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
            $table->decimal('base_total', 18, 4)->default(0); // In base currency
            $table->decimal('amount_paid', 18, 4)->default(0);
            $table->decimal('amount_due', 18, 4)->default(0);

            // Status
            $table->enum('status', [
                'draft',
                'sent',
                'partial',
                'paid',
                'overdue',
                'voided',
            ])->default('draft');

            // Compliance fields (populated by CompliPay)
            $table->enum('compliance_status', [
                'not_applicable',
                'pending',
                'submitted',
                'cleared',
                'reported',
                'rejected',
            ])->default('not_applicable');
            $table->string('compliance_uuid', 100)->nullable();
            $table->string('compliance_hash', 64)->nullable();
            $table->text('compliance_qr_code')->nullable();
            $table->json('compliance_response')->nullable();
            $table->timestamp('compliance_submitted_at')->nullable();

            // India GST specific
            $table->string('place_of_supply', 2)->nullable(); // State code
            $table->boolean('is_reverse_charge')->default(false);

            // Additional info
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->string('reference', 100)->nullable(); // Customer PO number

            // Optimistic locking
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'invoice_number']);
            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'invoice_date']);
            $table->index(['organization_id', 'due_date', 'status']);
            $table->index('compliance_uuid');
        });

        // Invoice Lines
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            // Product reference
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->text('description');

            // Quantity and pricing
            $table->decimal('quantity', 18, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('unit_price', 18, 4);

            // Discount
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);

            // Tax
            $table->foreignId('tax_category_id')->nullable()->constrained('tax_categories')->nullOnDelete();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->string('tax_code', 10)->nullable(); // S, Z, E, O

            // GST split (for India)
            $table->decimal('cgst_rate', 8, 4)->default(0);
            $table->decimal('cgst_amount', 18, 4)->default(0);
            $table->decimal('sgst_rate', 8, 4)->default(0);
            $table->decimal('sgst_amount', 18, 4)->default(0);
            $table->decimal('igst_rate', 8, 4)->default(0);
            $table->decimal('igst_amount', 18, 4)->default(0);
            $table->string('hsn_code', 20)->nullable();

            // Totals
            $table->decimal('subtotal', 18, 4)->default(0); // Before tax
            $table->decimal('total', 18, 4)->default(0); // After tax

            // Accounting
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};

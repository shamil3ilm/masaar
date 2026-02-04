<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bulk sale batches
        Schema::create('bulk_sale_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_number', 30);
            $table->string('name')->nullable();
            $table->date('sale_date'); // Allows backdating
            $table->date('original_sale_date')->nullable(); // If different from entry date
            $table->string('currency_code', 3)->default('SAR');

            // Totals
            $table->unsignedInteger('total_invoices')->default(0);
            $table->decimal('total_subtotal', 15, 2)->default(0);
            $table->decimal('total_discount', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            // Status
            $table->string('status', 20)->default('draft'); // draft, processing, completed, partially_completed, failed
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            // Processing
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Options
            $table->boolean('auto_post')->default(false);
            $table->boolean('auto_send_email')->default(false);
            $table->boolean('generate_receipts')->default(false);
            $table->string('payment_method')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'sale_date']);
            $table->index(['organization_id', 'status']);
        });

        // Bulk sale items (individual sales in batch)
        Schema::create('bulk_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('bulk_sale_batches')->cascadeOnDelete();
            $table->unsignedInteger('line_number');

            // Customer
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_tax_number')->nullable();

            // Sale details (can be simplified or use product)
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);

            // Payment
            $table->string('payment_status', 20)->default('unpaid'); // unpaid, paid, partial
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->string('payment_reference')->nullable();

            // Processing status
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed, skipped
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_id')->nullable(); // payments_received
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });

        // Quick sale templates (for POS-style bulk entry)
        Schema::create('quick_sale_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('default_items')->nullable(); // Pre-configured line items
            $table->foreignId('default_customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('default_payment_method')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        // Backdated transaction log (audit trail for backdated entries)
        Schema::create('backdated_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('transaction'); // invoice, payment, journal_entry, etc.
            $table->date('transaction_date'); // The backdated date used
            $table->date('entry_date'); // Actual date of entry
            $table->string('reason')->nullable(); // Reason for backdating
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'transaction_date']);
            $table->index(['transaction_type', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backdated_transactions');
        Schema::dropIfExists('quick_sale_templates');
        Schema::dropIfExists('bulk_sale_items');
        Schema::dropIfExists('bulk_sale_batches');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payments Received (from customers)
        Schema::create('payments_received', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('payment_number', 50);
            $table->date('payment_date');

            // Customer
            $table->foreignId('customer_id')->constrained('contacts');

            // Payment details
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->enum('payment_method', [
                'cash',
                'bank_transfer',
                'cheque',
                'credit_card',
                'online',
                'other',
            ])->default('bank_transfer');

            $table->decimal('amount', 18, 4);
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_amount', 18, 4); // In organization's base currency

            // Reference info
            $table->string('reference', 100)->nullable(); // Cheque number, transaction ID, etc.
            $table->text('notes')->nullable();

            // Status
            $table->enum('status', [
                'pending',      // Created but not confirmed
                'completed',    // Payment confirmed
                'voided',       // Cancelled
                'bounced',      // Cheque bounced
            ])->default('pending');

            // Accounting
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'payment_number']);
            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'payment_date']);
            $table->index(['organization_id', 'status']);
        });

        // Payment Allocations (linking payments to invoices)
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_received_id')->constrained('payments_received')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 18, 4);
            $table->decimal('base_amount', 18, 4);

            $table->timestamp('allocated_at');
            $table->timestamps();

            $table->unique(['payment_received_id', 'invoice_id']);
            $table->index('invoice_id');
        });

        // Customer Credits (advance payments, overpayments)
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('contacts');

            $table->enum('source_type', [
                'advance_payment',
                'credit_note',
                'overpayment',
                'adjustment',
            ]);
            $table->unsignedBigInteger('source_id')->nullable();

            $table->decimal('original_amount', 18, 4);
            $table->decimal('remaining_amount', 18, 4);
            $table->string('currency_code', 3)->default('SAR');

            $table->date('credit_date');
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['organization_id', 'customer_id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments_received');
    }
};

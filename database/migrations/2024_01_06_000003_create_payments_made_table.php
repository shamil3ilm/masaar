<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payments Made (to suppliers)
        Schema::create('payments_made', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('payment_number', 50);
            $table->date('payment_date');

            // Supplier
            $table->foreignId('supplier_id')->constrained('contacts');

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
            $table->decimal('base_amount', 18, 4);

            // Reference info
            $table->string('reference', 100)->nullable(); // Cheque number, transaction ID
            $table->text('notes')->nullable();

            // Status
            $table->enum('status', [
                'pending',
                'completed',
                'voided',
                'bounced',
            ])->default('pending');

            // Accounting
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'payment_number']);
            $table->index(['organization_id', 'supplier_id']);
            $table->index(['organization_id', 'payment_date']);
            $table->index(['organization_id', 'status']);
        });

        // Payment Allocations (linking payments to bills)
        Schema::create('bill_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_made_id')->constrained('payments_made')->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 18, 4);
            $table->decimal('base_amount', 18, 4);

            $table->timestamp('allocated_at');
            $table->timestamps();

            $table->unique(['payment_made_id', 'bill_id']);
            $table->index('bill_id');
        });

        // Supplier Credits (advance payments, credits from supplier)
        Schema::create('supplier_credits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('contacts');

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

            $table->index(['organization_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credits');
        Schema::dropIfExists('bill_payment_allocations');
        Schema::dropIfExists('payments_made');
    }
};

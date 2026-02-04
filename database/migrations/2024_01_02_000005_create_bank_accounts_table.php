<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Bank details
            $table->string('bank_name', 100);
            $table->string('account_name', 100);
            $table->string('account_number', 50);
            $table->string('iban', 50)->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->string('branch_name', 100)->nullable();
            $table->string('branch_code', 20)->nullable();

            // Currency and account type
            $table->string('currency_code', 3);
            $table->enum('account_type', ['current', 'savings', 'credit_card', 'cash'])->default('current');

            // Link to Chart of Accounts
            $table->foreignId('gl_account_id')->constrained('chart_of_accounts');

            // Current balance (updated via triggers or calculated)
            $table->decimal('current_balance', 18, 4)->default(0);
            $table->date('last_reconciled_date')->nullable();
            $table->decimal('last_reconciled_balance', 18, 4)->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'account_number', 'bank_name']);
            $table->index(['organization_id', 'is_active']);

            $table->foreign('currency_code')->references('code')->on('currencies');
        });

        // Bank transactions for reconciliation
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('reference', 100)->nullable();
            $table->text('description')->nullable();

            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->decimal('running_balance', 18, 4)->default(0);

            // Reconciliation
            $table->boolean('is_reconciled')->default(false);
            $table->date('reconciled_date')->nullable();

            // Link to journal entry
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_line_id')->nullable()->constrained('journal_entry_lines')->nullOnDelete();

            // Source document
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->timestamps();

            $table->index(['bank_account_id', 'transaction_date']);
            $table->index(['bank_account_id', 'is_reconciled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
    }
};

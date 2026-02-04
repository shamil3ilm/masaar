<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bank accounts (extend existing or create new)
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('account_name');
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('iban')->nullable();
            $table->string('swift_code')->nullable();
            $table->string('currency_code', 3)->default('SAR');
            $table->string('account_type', 20)->default('checking'); // checking, savings, credit_card
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->decimal('bank_balance', 15, 2)->default(0); // Last known bank balance
            $table->date('last_reconciled_date')->nullable();
            $table->decimal('last_reconciled_balance', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable(); // Bank-specific settings
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        // Bank transactions (imported from bank statements)
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('description');
            $table->string('transaction_type', 20); // debit, credit
            $table->decimal('amount', 15, 2);
            $table->decimal('balance', 15, 2)->nullable(); // Running balance from bank
            $table->string('status', 20)->default('unmatched'); // unmatched, matched, excluded, reconciled
            $table->string('category')->nullable(); // Auto-categorized
            $table->foreignId('matched_transaction_id')->nullable(); // Link to internal transaction
            $table->string('matched_transaction_type')->nullable(); // payment, receipt, journal, etc.
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->string('import_source', 50)->nullable(); // manual, csv, ofx, api
            $table->string('import_batch_id')->nullable();
            $table->json('raw_data')->nullable(); // Original import data
            $table->timestamps();

            $table->index(['bank_account_id', 'transaction_date']);
            $table->index(['bank_account_id', 'status']);
            $table->index(['organization_id', 'status']);
        });

        // Reconciliation sessions
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2);
            $table->decimal('book_balance', 15, 2);
            $table->decimal('adjusted_book_balance', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->default(0);
            $table->string('status', 20)->default('in_progress'); // in_progress, completed, cancelled
            $table->json('summary')->nullable(); // Reconciliation summary
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'status']);
        });

        // Reconciliation line items
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->string('item_type', 30); // bank_transaction, outstanding_check, outstanding_deposit, adjustment
            $table->date('transaction_date');
            $table->string('reference')->nullable();
            $table->text('description');
            $table->decimal('amount', 15, 2);
            $table->boolean('is_cleared')->default(false);
            $table->timestamps();

            $table->index(['reconciliation_id', 'is_cleared']);
        });

        // Bank statement imports
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type', 10); // csv, ofx, qfx, mt940
            $table->date('statement_start_date')->nullable();
            $table->date('statement_end_date')->nullable();
            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('imported_transactions')->default(0);
            $table->unsignedInteger('duplicate_transactions')->default(0);
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'created_at']);
        });

        // Matching rules for auto-categorization
        Schema::create('bank_matching_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('match_field', 30); // description, reference, amount
            $table->string('match_type', 20); // contains, starts_with, equals, regex
            $table->string('match_value');
            $table->string('transaction_type', 10)->nullable(); // debit, credit
            $table->string('action', 30); // categorize, match_contact, match_account, exclude
            $table->json('action_data'); // Category, account_id, contact_id, etc.
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_matching_rules');
        Schema::dropIfExists('bank_statement_imports');
        Schema::dropIfExists('bank_reconciliation_items');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
    }
};

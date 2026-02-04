<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->text('description')->nullable();

            // Account classification
            $table->enum('account_type', [
                'asset',
                'liability',
                'equity',
                'income',
                'expense',
            ]);

            // More specific sub-types for system behavior
            $table->enum('sub_type', [
                // Assets
                'cash',
                'bank',
                'receivable',
                'inventory',
                'fixed_asset',
                'other_asset',
                // Liabilities
                'payable',
                'credit_card',
                'tax_payable',
                'other_liability',
                // Equity
                'capital',
                'retained_earnings',
                'drawings',
                // Income
                'sales',
                'other_income',
                // Expense
                'cost_of_goods',
                'operating_expense',
                'other_expense',
            ]);

            $table->string('currency_code', 3)->nullable(); // If account is in specific currency
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // System accounts can't be deleted
            $table->boolean('is_header')->default(false); // Header accounts can't have transactions

            // For tree structure ordering
            $table->unsignedInteger('level')->default(1);
            $table->string('path', 255)->nullable(); // e.g., "1.2.3" for hierarchy

            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'account_type']);
            $table->index(['organization_id', 'sub_type']);
            $table->index(['organization_id', 'parent_id']);

            $table->foreign('currency_code')->references('code')->on('currencies')->nullOnDelete();
        });

        // Account opening balances (per fiscal year)
        Schema::create('account_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_opening_balances');
        Schema::dropIfExists('chart_of_accounts');
    }
};

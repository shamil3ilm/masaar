<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();

            // Entry identification
            $table->string('entry_number', 50);
            $table->date('entry_date');
            $table->string('reference', 100)->nullable(); // External reference
            $table->text('description')->nullable();

            // Source document (invoice, bill, payment, etc.)
            $table->string('source_type', 50)->nullable(); // invoice, bill, payment, manual
            $table->unsignedBigInteger('source_id')->nullable();

            // Currency handling
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);

            // Totals (must be equal for balanced entry)
            $table->decimal('total_debit', 18, 4)->default(0);
            $table->decimal('total_credit', 18, 4)->default(0);

            // Status workflow
            $table->enum('status', ['draft', 'posted', 'voided'])->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            // Reversal tracking
            $table->foreignId('reversed_by_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'entry_number']);
            $table->index(['organization_id', 'entry_date']);
            $table->index(['organization_id', 'status']);
            $table->index(['source_type', 'source_id']);
            $table->index(['organization_id', 'fiscal_year_id']);

            $table->foreign('currency_code')->references('code')->on('currencies');
        });

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();

            $table->text('description')->nullable();

            // Amounts in transaction currency
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);

            // Amounts in base currency (for reporting)
            $table->decimal('base_debit', 18, 4)->default(0);
            $table->decimal('base_credit', 18, 4)->default(0);

            // Optional dimensions
            $table->foreignId('cost_center_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable(); // Customer/Supplier

            // Line ordering
            $table->unsignedSmallInteger('line_order')->default(0);

            $table->timestamps();

            $table->index(['journal_entry_id', 'line_order']);
            $table->index(['account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
    }
};

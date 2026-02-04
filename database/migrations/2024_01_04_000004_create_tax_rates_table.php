<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tax Rates (per country/category)
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_category_id')->constrained()->cascadeOnDelete();

            $table->string('name', 100);
            $table->decimal('rate', 8, 4);  // e.g., 15.0000 for 15%
            $table->string('country_code', 2);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tax_category_id', 'country_code']);
            $table->index(['country_code', 'effective_from']);
        });

        // HSN/SAC Codes (for India GST)
        Schema::create('hsn_sac_codes', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20)->unique();
            $table->text('description');
            $table->decimal('gst_rate', 5, 2);  // 0, 5, 12, 18, 28
            $table->enum('type', ['goods', 'service'])->default('goods');

            $table->timestamps();

            $table->index('gst_rate');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hsn_sac_codes');
        Schema::dropIfExists('tax_rates');
    }
};

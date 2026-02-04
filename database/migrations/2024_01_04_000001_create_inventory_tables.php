<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Units of Measure
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50); // e.g., "Kilogram", "Piece", "Box"
            $table->string('symbol', 10); // e.g., "kg", "pc", "box"
            $table->foreignId('base_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('conversion_factor', 18, 8)->default(1); // How many base units
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'symbol']);
        });

        // Product Categories (hierarchical)
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedInteger('level')->default(1);
            $table->string('path', 255)->nullable(); // e.g., "1.2.3" for hierarchy
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'parent_id']);
        });

        // Tax Categories (for product tax classification)
        Schema::create('tax_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50); // e.g., "Standard Rate", "Zero Rated"
            $table->string('code', 10); // S, Z, E, O (ZATCA codes)
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // Tax Rates (per category and country)
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_category_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->decimal('rate', 5, 2); // e.g., 15.00 for 15%
            $table->string('country_code', 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tax_category_id', 'country_code', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_categories');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('units_of_measure');
    }
};

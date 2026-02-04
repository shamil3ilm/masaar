<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Import jobs tracking
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 50); // customers, suppliers, products, employees, etc.
            $table->string('file_name');
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed, cancelled
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->json('column_mapping')->nullable(); // Maps file columns to entity fields
            $table->json('options')->nullable(); // Import options (update_existing, skip_errors, etc.)
            $table->json('errors')->nullable(); // Array of row-level errors
            $table->json('summary')->nullable(); // Import summary stats
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'entity_type']);
            $table->index('created_at');
        });

        // Export jobs tracking
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 50); // customers, invoices, products, etc.
            $table->string('format', 10)->default('xlsx'); // xlsx, csv, pdf
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed
            $table->json('filters')->nullable(); // Applied filters for export
            $table->json('columns')->nullable(); // Selected columns to export
            $table->json('options')->nullable(); // Export options
            $table->unsignedInteger('total_records')->default(0);
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // When the file will be deleted
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'entity_type']);
            $table->index('expires_at');
        });

        // Import templates - predefined mappings for common imports
        Schema::create('import_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('entity_type', 50);
            $table->json('column_mapping');
            $table->json('options')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'name', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_templates');
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('import_jobs');
    }
};

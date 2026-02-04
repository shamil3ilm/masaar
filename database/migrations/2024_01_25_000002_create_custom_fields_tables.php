<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custom field definitions
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 50); // invoice, customer, product, employee, etc.
            $table->string('field_name', 50); // Internal name (snake_case)
            $table->string('field_label'); // Display label
            $table->string('field_type', 30); // text, number, decimal, date, datetime, boolean, select, multiselect, textarea, file, url, email, phone
            $table->text('description')->nullable();
            $table->json('options')->nullable(); // For select/multiselect: [{value: 'x', label: 'X'}]
            $table->json('validation')->nullable(); // {required: true, min: 0, max: 100, pattern: '', etc.}
            $table->string('default_value')->nullable();
            $table->string('placeholder')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->string('field_group')->nullable(); // Group fields together in UI
            $table->boolean('is_required')->default(false);
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('show_in_list')->default(false); // Show in list/table view
            $table->boolean('show_in_form')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'entity_type', 'field_name']);
            $table->index(['organization_id', 'entity_type', 'is_active']);
        });

        // Custom field values
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();
            $table->morphs('entity'); // The entity this value belongs to
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->datetime('value_datetime')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->json('value_json')->nullable(); // For multiselect, file references, etc.
            $table->timestamps();

            $table->unique(['field_definition_id', 'entity_type', 'entity_id'], 'custom_field_value_unique');
            $table->index(['entity_type', 'entity_id']);
        });

        // Field groups (for organizing custom fields)
        Schema::create('custom_field_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 50);
            $table->string('name');
            $table->string('slug', 50);
            $table->text('description')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_collapsible')->default(true);
            $table->boolean('is_collapsed_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'entity_type', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_groups');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
    }
};

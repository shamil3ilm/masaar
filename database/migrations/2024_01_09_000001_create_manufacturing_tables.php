<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bill of Materials (BOM) Templates
        Schema::create('bom_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('bom_number', 50);
            $table->string('name', 200);
            $table->text('description')->nullable();

            // Finished product
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('output_quantity', 15, 4)->default(1);
            $table->foreignId('output_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();

            // Defaults
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->unsignedSmallInteger('estimated_hours')->nullable();
            $table->decimal('estimated_labor_cost', 15, 4)->nullable();
            $table->decimal('overhead_cost', 15, 4)->default(0);

            // Status
            $table->enum('status', ['draft', 'active', 'inactive'])->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->unsignedSmallInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'bom_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'product_id']);
        });

        // BOM Lines (raw materials/components)
        Schema::create('bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('quantity', 15, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('wastage_percentage', 5, 2)->default(0);
            $table->boolean('is_critical')->default(false); // Production stops if unavailable
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();
        });

        // BOM Operations (manufacturing steps)
        Schema::create('bom_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_template_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedSmallInteger('estimated_minutes')->default(0);
            $table->decimal('labor_cost_per_hour', 15, 4)->nullable();
            $table->string('workstation', 100)->nullable();
            $table->json('required_skills')->nullable();
            $table->boolean('is_subcontracted')->default(false);
            $table->timestamps();
        });

        // Work Orders
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Work order info
            $table->string('work_order_number', 50);
            $table->foreignId('bom_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->nullable();
            $table->foreignId('sales_order_line_id')->nullable();

            // Product to manufacture
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('planned_quantity', 15, 4);
            $table->decimal('produced_quantity', 15, 4)->default(0);
            $table->decimal('rejected_quantity', 15, 4)->default(0);
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();

            // Dates
            $table->date('planned_start_date');
            $table->date('planned_end_date');
            $table->datetime('actual_start_datetime')->nullable();
            $table->datetime('actual_end_datetime')->nullable();

            // Warehouse
            $table->foreignId('source_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('target_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Costs
            $table->decimal('estimated_material_cost', 15, 4)->default(0);
            $table->decimal('estimated_labor_cost', 15, 4)->default(0);
            $table->decimal('estimated_overhead_cost', 15, 4)->default(0);
            $table->decimal('actual_material_cost', 15, 4)->default(0);
            $table->decimal('actual_labor_cost', 15, 4)->default(0);
            $table->decimal('actual_overhead_cost', 15, 4)->default(0);

            // Status
            $table->enum('status', [
                'draft',
                'pending',
                'scheduled',
                'in_progress',
                'completed',
                'cancelled',
            ])->default('draft');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');

            // Assignment
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'work_order_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'planned_start_date']);
        });

        // Work Order Material Consumption
        Schema::create('work_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bom_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('description', 500)->nullable();

            // Quantities
            $table->decimal('required_quantity', 15, 4);
            $table->decimal('issued_quantity', 15, 4)->default(0);
            $table->decimal('consumed_quantity', 15, 4)->default(0);
            $table->decimal('returned_quantity', 15, 4)->default(0);
            $table->decimal('wastage_quantity', 15, 4)->default(0);
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();

            // Cost
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 4)->default(0);

            // Warehouse
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index(['work_order_id', 'product_id']);
        });

        // Work Order Operations (tasks)
        Schema::create('work_order_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bom_operation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);

            // Time
            $table->unsignedSmallInteger('estimated_minutes')->default(0);
            $table->unsignedSmallInteger('actual_minutes')->default(0);
            $table->datetime('started_at')->nullable();
            $table->datetime('completed_at')->nullable();

            // Status
            $table->enum('status', [
                'pending',
                'in_progress',
                'completed',
                'skipped',
            ])->default('pending');

            // Assignment
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'status']);
        });

        // Production Log (tracking production output)
        Schema::create('production_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->datetime('logged_at');

            // Quantities
            $table->decimal('quantity_produced', 15, 4);
            $table->decimal('quantity_rejected', 15, 4)->default(0);
            $table->string('rejection_reason', 500)->nullable();

            // Quality
            $table->boolean('quality_checked')->default(false);
            $table->foreignId('quality_checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('quality_checked_at')->nullable();
            $table->json('quality_parameters')->nullable();

            // Batch/Lot tracking
            $table->string('batch_number', 100)->nullable();
            $table->string('lot_number', 100)->nullable();
            $table->date('expiry_date')->nullable();

            // Movement
            $table->foreignId('stock_movement_id')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'work_order_id']);
            $table->index(['organization_id', 'logged_at']);
        });

        // Material Issues/Returns
        Schema::create('material_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_material_id')->constrained()->cascadeOnDelete();
            $table->enum('transaction_type', ['issue', 'return', 'wastage']);
            $table->datetime('transaction_datetime');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('stock_movement_id')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'work_order_id']);
            $table->index(['organization_id', 'transaction_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_transactions');
        Schema::dropIfExists('production_logs');
        Schema::dropIfExists('work_order_operations');
        Schema::dropIfExists('work_order_materials');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('bom_operations');
        Schema::dropIfExists('bom_lines');
        Schema::dropIfExists('bom_templates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Warehouses
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 100);
            $table->string('code', 20);
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();

            // Warehouse manager
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_negative_stock')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        // Warehouse Locations (bins, shelves, zones)
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('name', 50);
            $table->string('code', 20);
            $table->enum('type', ['zone', 'aisle', 'rack', 'shelf', 'bin'])->default('bin');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['warehouse_id', 'code']);
        });

        // Stock Levels (current inventory per product/warehouse)
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();

            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('reserved_quantity', 18, 4)->default(0); // Reserved for orders
            $table->decimal('available_quantity', 18, 4)
                ->storedAs('quantity - reserved_quantity'); // Computed column

            // Costing
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->decimal('last_purchase_price', 18, 4)->nullable();
            $table->decimal('total_value', 18, 4)->default(0);

            // Reorder
            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->decimal('reorder_quantity', 18, 4)->nullable();
            $table->decimal('maximum_stock', 18, 4)->nullable();

            $table->timestamp('last_count_date')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'variant_id', 'warehouse_id', 'location_id'], 'stock_level_unique');
            $table->index(['organization_id', 'warehouse_id']);
            $table->index(['product_id', 'warehouse_id']);
        });

        // Stock Movements (all inventory transactions)
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();

            // Movement type
            $table->enum('movement_type', [
                'purchase',      // Goods received from purchase
                'sale',          // Goods sold
                'transfer_in',   // Received from another warehouse
                'transfer_out',  // Sent to another warehouse
                'adjustment',    // Manual adjustment
                'return_in',     // Customer return
                'return_out',    // Return to supplier
                'production_in', // Finished goods from manufacturing
                'production_out',// Raw materials consumed
                'opening',       // Opening balance
            ]);

            // Direction and quantity
            $table->enum('direction', ['in', 'out']);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);

            // Running balance after this movement
            $table->decimal('balance_after', 18, 4);

            // Source document reference
            $table->string('reference_type', 50)->nullable(); // invoice, bill, transfer, adjustment
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number', 50)->nullable();

            // For transfers
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'product_id']);
            $table->index(['organization_id', 'warehouse_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        // Stock Adjustments (for manual adjustments with reasons)
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            $table->string('adjustment_number', 50);
            $table->date('adjustment_date');
            $table->enum('reason', [
                'damage',
                'theft',
                'expiry',
                'count_correction',
                'opening_balance',
                'other',
            ]);
            $table->text('notes')->nullable();

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'adjustment_number']);
        });

        // Stock Adjustment Lines
        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations');

            $table->decimal('system_quantity', 18, 4); // Before adjustment
            $table->decimal('actual_quantity', 18, 4); // After count
            $table->decimal('difference', 18, 4); // Computed difference
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();
        });

        // Stock Transfers
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('transfer_number', 50);
            $table->date('transfer_date');
            $table->date('expected_arrival_date')->nullable();

            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');

            $table->text('notes')->nullable();

            $table->enum('status', ['draft', 'in_transit', 'received', 'cancelled'])->default('draft');
            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'transfer_number']);
        });

        // Stock Transfer Lines
        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');

            $table->decimal('quantity_sent', 18, 4);
            $table->decimal('quantity_received', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('warehouses');
    }
};

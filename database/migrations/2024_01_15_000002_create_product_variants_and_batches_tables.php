<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enhanced Units of Measure
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('symbol', 10);
            $table->string('type', 20); // weight, volume, length, piece, pack, time
            $table->foreignId('base_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('conversion_factor', 15, 6)->default(1); // How many base units in this unit
            $table->boolean('is_base_unit')->default(false);
            $table->boolean('allow_decimal')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'symbol']);
        });

        // Product variants (for products with variations like size, color)
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 50);
            $table->string('name', 200);
            $table->json('attributes')->nullable(); // {size: "L", color: "Red"}
            $table->string('barcode', 50)->nullable();
            $table->decimal('price_adjustment', 15, 4)->default(0); // +/- from base price
            $table->decimal('cost_adjustment', 15, 4)->default(0);
            $table->decimal('weight', 10, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('sku');
            $table->index('barcode');
        });

        // Batch/Lot tracking for inventory
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 50);
            $table->string('lot_number', 50)->nullable();
            $table->string('serial_number', 100)->nullable(); // For serialized items
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('received_date');
            $table->decimal('quantity', 15, 4);
            $table->decimal('reserved_quantity', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 4);
            $table->string('status', 20)->default('available'); // available, reserved, expired, damaged, quarantine
            $table->foreignId('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('grn_number', 50)->nullable(); // Goods Receipt Note reference
            $table->json('metadata')->nullable(); // Additional batch attributes
            $table->timestamps();

            $table->unique(['organization_id', 'product_id', 'warehouse_id', 'batch_number']);
            $table->index(['organization_id', 'expiry_date']);
            $table->index('serial_number');
        });

        // Product barcodes (multiple barcodes per product)
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('barcode', 50);
            $table->string('barcode_type', 20)->default('EAN13'); // EAN13, EAN8, UPC, CODE128, QR
            $table->boolean('is_primary')->default(false);
            $table->decimal('quantity', 15, 4)->default(1); // Quantity represented by this barcode (for packs)
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->timestamps();

            $table->unique('barcode');
            $table->index(['product_id', 'is_primary']);
        });

        // Product packaging/units (for selling in different units)
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units_of_measure')->cascadeOnDelete();
            $table->decimal('conversion_factor', 15, 6); // How many base units
            $table->string('barcode', 50)->nullable();
            $table->decimal('selling_price', 15, 4)->nullable();
            $table->decimal('purchase_price', 15, 4)->nullable();
            $table->boolean('is_purchase_unit')->default(false);
            $table->boolean('is_sales_unit')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
        });

        // Add columns to products table
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_type', 30)->default('goods')->after('type');
            // goods, service, consumable, digital, bundle
            $table->foreignId('base_unit_id')->nullable()->after('unit_id')
                ->constrained('units_of_measure')->nullOnDelete();
            $table->boolean('track_inventory')->default(true)->after('base_unit_id');
            $table->boolean('track_batches')->default(false)->after('track_inventory');
            $table->boolean('track_serials')->default(false)->after('track_batches');
            $table->boolean('has_expiry')->default(false)->after('track_serials');
            $table->unsignedSmallInteger('expiry_warning_days')->nullable()->after('has_expiry');
            $table->boolean('allow_negative_stock')->default(false)->after('expiry_warning_days');
            $table->boolean('sell_below_cost')->default(true)->after('allow_negative_stock');
            $table->decimal('minimum_stock', 15, 4)->nullable()->after('sell_below_cost');
            $table->decimal('maximum_stock', 15, 4)->nullable()->after('minimum_stock');
            $table->decimal('reorder_point', 15, 4)->nullable()->after('maximum_stock');
            $table->decimal('reorder_quantity', 15, 4)->nullable()->after('reorder_point');
            $table->decimal('weight', 10, 4)->nullable()->after('reorder_quantity');
            $table->string('weight_unit', 10)->nullable()->after('weight');
            $table->decimal('length', 10, 4)->nullable()->after('weight_unit');
            $table->decimal('width', 10, 4)->nullable()->after('length');
            $table->decimal('height', 10, 4)->nullable()->after('width');
            $table->string('dimension_unit', 10)->nullable()->after('height');
            $table->boolean('is_loose_item')->default(false)->after('dimension_unit'); // Sold by weight
            $table->decimal('tare_weight', 10, 4)->nullable()->after('is_loose_item'); // Package weight to deduct
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['base_unit_id']);
            $table->dropColumn([
                'product_type',
                'base_unit_id',
                'track_inventory',
                'track_batches',
                'track_serials',
                'has_expiry',
                'expiry_warning_days',
                'allow_negative_stock',
                'sell_below_cost',
                'minimum_stock',
                'maximum_stock',
                'reorder_point',
                'reorder_quantity',
                'weight',
                'weight_unit',
                'length',
                'width',
                'height',
                'dimension_unit',
                'is_loose_item',
                'tare_weight',
            ]);
        });

        Schema::dropIfExists('product_units');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('units_of_measure');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The saved product an invoice line was made from, as invoices.customer_id
 * is the saved customer an invoice is for: so a product that was ever used
 * on an invoice is known, and can only be archived, never removed (owner
 * decision). The line's own description, price and tax stay what it says;
 * this only says whose they were.
 *
 * Lines written before this named no product. Each is matched to the
 * organization's product it was filled from - the product's name as the
 * line's description, or as its item name on a UAE line - so a product
 * already used is kept as used. A match by name can only call a product
 * used that was not, which keeps it; never the other way round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
        });

        DB::table('products')->orderBy('id')->select(['id', 'org_id', 'name'])->chunk(200, function ($products): void {
            foreach ($products as $product) {
                DB::table('invoice_lines')
                    ->whereNull('product_id')
                    ->where(fn ($query) => $query->where('description', $product->name)->orWhere('item_name', $product->name))
                    ->whereIn('invoice_id', DB::table('invoices')->where('org_id', $product->org_id)->select('id'))
                    ->update(['product_id' => $product->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};

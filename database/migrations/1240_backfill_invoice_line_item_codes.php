<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The item codes of lines on invoices issued before LineSnapshot copied
 * them: best effort, from each line's product as it is now - the code it
 * printed until now - including a product since removed. A line whose
 * product is gone altogether (its product_id nulled) has none to copy.
 * Drafts are left alone: they take theirs when issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_lines.product_id')
            ->where('invoices.status', '!=', 'draft')
            ->whereNull('invoice_lines.item_code')
            ->whereNotNull('products.sku')
            ->where('products.sku', '!=', '')
            ->orderBy('invoice_lines.id')
            ->select(['invoice_lines.id', 'products.sku'])
            ->chunk(500, function ($lines): void {
                foreach ($lines as $line) {
                    DB::table('invoice_lines')->where('id', $line->id)->update(['item_code' => $line->sku]);
                }
            });
    }

    /**
     * Nothing: the copies stand as any issued line's do, and go with the
     * column when 1230 is rolled back. Clearing them would also clear those
     * LineSnapshot took since.
     */
    public function down(): void
    {
        //
    }
};

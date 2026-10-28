<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Models\Product;

/**
 * What a line keeps of the saved product it was made from, written at the
 * moment the invoice is issued.
 *
 * A printed invoice is the issued document, so it shows what was issued -
 * not the product as it reads today, after a code was changed or the
 * product removed. A line already copies its description, unit, price and
 * tax when it is made; the item code is the one product detail it printed
 * from the product itself, so it is copied here (invoice_lines.item_code),
 * once, while the invoice is still a draft and its lines may be written.
 *
 * Invoice's own updating event calls this when the invoice leaves the
 * draft, whichever way it is issued. A line the seller typed, with no
 * product, keeps none.
 */
final class LineSnapshot
{
    public static function take(Invoice $invoice): void
    {
        $lines = InvoiceLine::query()
            ->where('invoice_id', $invoice->id)
            ->whereNotNull('product_id')
            ->get(['id', 'product_id']);

        if ($lines->isEmpty()) {
            return;
        }

        $codes = Product::acrossTenants()->withTrashed()
            ->where('org_id', $invoice->org_id)
            ->whereIn('id', $lines->pluck('product_id')->unique()->all())
            ->pluck('sku', 'id')
            ->all();

        foreach ($lines as $line) {
            $code = trim((string) ($codes[$line->product_id] ?? ''));

            // A query write: the invoice is leaving the draft in this same
            // save, and the line's model guard is for edits after it has.
            InvoiceLine::query()->whereKey($line->id)->toBase()->update(['item_code' => $code === '' ? null : $code]);
        }
    }
}

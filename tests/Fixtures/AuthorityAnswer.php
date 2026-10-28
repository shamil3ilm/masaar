<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domains\Invoice\Models\Invoice;

/**
 * A draft fixture taken to the authority's answer the way the lifecycle
 * takes a real one (InvoiceStatus::next()): issued, with the columns an
 * issuance writes, then accepted or rejected. The model refuses a draft
 * jumping straight to an answer, so a fixture walks the same two steps
 * rather than going round the guard.
 */
final class AuthorityAnswer
{
    /**
     * @param  'draft'|'issued'|'accepted'|'rejected'  $status
     * @param  array<string, mixed>  $issued  what issuing writes: number, hash, issued_at ...
     */
    public static function record(Invoice $invoice, string $status, array $issued = []): Invoice
    {
        // A fixture that stays a draft only takes its columns.
        if ($status === 'draft') {
            $invoice->forceFill([...$issued, 'status' => 'draft'])->save();

            return $invoice;
        }

        $invoice->forceFill([...$issued, 'status' => 'issued'])->save();

        if ($status !== 'issued') {
            $invoice->forceFill(['status' => $status])->save();
        }

        return $invoice;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * The invoice a credit or debit note corrects, on record.
 *
 * A note is refused before it is stamped unless the invoice it references
 * was issued by the same organization, is of its type, and - for a credit
 * note - has room left, and was accepted by ZATCA (IR Art 54(4), BR-KSA-56;
 * IssuingStep). A test that issues a note needs that original. Recorded as
 * accepted rather than issued and filed here, so the note keeps its own
 * place in the device's chain.
 */
trait RecordedOriginals
{
    private function recordedOriginal(Organization $organization, string $number, string $type, string $total = '100000.00'): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->firstOrCreate(
            ['org_id' => $organization->id, 'invoice_number' => $number],
            [
                'type' => $type, 'document_type' => 'invoice', 'status' => 'accepted',
                'issue_date' => now()->toDateString(), 'currency' => 'SAR',
                'subtotal' => $total, 'tax_amount' => '0.00', 'total' => $total,
            ],
        ));
    }
}

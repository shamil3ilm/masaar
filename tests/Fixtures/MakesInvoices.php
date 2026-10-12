<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * An invoice to test against.
 *
 * There is no InvoiceFactory - database/factories holds only UserFactory and
 * Invoice does not use HasFactory - so every test that needs one has been
 * writing its own Invoice::create. This lifts the helper from
 * PortalDataScopeTest verbatim so the two cannot drift into disagreeing about
 * what a valid invoice looks like.
 *
 * withoutTenantScope because the fixture is built before any request has
 * resolved a tenant, and the scope would otherwise refuse the insert.
 */
trait MakesInvoices
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInvoice(Organization $organization, string $number, array $attributes = []): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create(array_merge([
            'org_id' => $organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ], $attributes)));
    }
}

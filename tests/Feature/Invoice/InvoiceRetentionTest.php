<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An organization's invoices outlive any attempt to delete it.
 *
 * invoices.org_id cascaded, so deleting the organization row - from a
 * console, a script, a future admin action - removed every invoice with it,
 * below the model guard that refuses to delete an issued one. The taxpayer
 * must keep them for six years. Deleting an organization that has invoices
 * is now refused by the database.
 */
class InvoiceRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_invoicing_organization_stays(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $acme->id,
            'invoice_number' => 'INV-1',
            'type' => 'simplified',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        try {
            $acme->delete();
            $this->fail('An organization with invoices was deleted.');
        } catch (QueryException) {
            // Refused, as it should be.
        }

        $this->assertNotNull(Invoice::withoutTenantScope(fn () => Invoice::find($invoice->id)));
    }

    public function test_an_empty_organization_can_go(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $acme->delete();

        $this->assertNull(Organization::find($acme->id));
    }
}

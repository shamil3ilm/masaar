<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\InvoiceIssuer;
use App\Domains\Compliance\Fatoora\Services\InvoiceValidator;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceTotals;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ZATCA's rules are checked before a counter is spent.
 *
 * InvoiceValidator held them - seller registration and address, buyer
 * identification, tax categories and exemptions, totals - and nothing called
 * it, so an invoice that broke them was signed, numbered, chained and only
 * then refused by the authority. Its totals rule had also drifted from the one
 * the document declares, so it is reconciled first.
 */
class IssuanceGateTest extends TestCase
{
    use RefreshDatabase;

    private const SELLER = [
        'name' => 'Acme Trading',
        'country' => 'SA',
        'vat_number' => '300000000000003',
        'street' => 'King Fahd Road',
        'building_number' => '1234',
        'district' => 'Al Olaya',
        'city' => 'Riyadh',
        'postal_code' => '12345',
    ];

    /**
     * Document VAT is per category after the discount, not the lines' own VAT
     * added up. The old rule refused this invoice.
     */
    public function test_a_discounted_invoice_validates(): void
    {
        $organization = Organization::create(self::SELLER);
        $invoice = $this->draft($organization, [['100.00', '15'], ['50.00', '15']], discount: '10.00');

        $result = app(InvoiceValidator::class)->validate($invoice, $organization);

        $this->assertSame([], $result['errors']);
    }

    public function test_a_rule_breaking_invoice_is_refused(): void
    {
        $organization = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $invoice = $this->draft($organization, [['100.00', '15']]);

        $outcome = app(InvoiceIssuer::class)->issue($invoice, $organization, file: false);

        $this->assertFalse($outcome->issued);
        $this->assertStringContainsString('BR-KSA-14', $outcome->errors[0]);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Draft, $fresh->status);
        $this->assertNull($fresh->icv, 'a counter was spent on an invoice that was refused');
    }

    public function test_a_valid_invoice_is_issued(): void
    {
        $organization = Organization::create(self::SELLER);
        $invoice = $this->draft($organization, [['100.00', '15']]);

        $this->assertTrue(app(InvoiceIssuer::class)->issue($invoice, $organization, file: false)->issued);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lines  unit price and VAT rate
     */
    private function draft(Organization $organization, array $lines, string $discount = '0'): Invoice
    {
        $rows = array_map(fn (array $line): array => [
            'quantity' => '1',
            'unit_price' => $line[0],
            'tax_rate' => $line[1],
            'tax_category' => 'S',
        ], $lines);

        $totals = InvoiceTotals::of($rows, $discount);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => $totals->subtotal,
            'discount_amount' => $totals->discount,
            'tax_amount' => $totals->tax,
            'total' => $totals->total,
        ]));

        foreach ($totals->lines as $index => $line) {
            $invoice->lines()->create([
                'description' => 'Item '.($index + 1),
                'quantity' => '1',
                'unit_price' => $rows[$index]['unit_price'],
                'tax_rate' => $line['rate'],
                'tax_amount' => $line['tax'],
                'tax_category' => 'S',
                'line_total' => $line['total'],
            ]);
        }

        return $invoice->fresh(['lines']);
    }
}

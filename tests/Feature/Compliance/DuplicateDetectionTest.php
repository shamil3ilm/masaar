<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\DuplicateDetector;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An invoice is not a duplicate of itself.
 *
 * The invoice is persisted before it is submitted, so every lookup here found
 * it: checkUuid() is Invoice::find() on the invoice's own primary key, and
 * checkInvoiceNumber() matched the row it was asked about. Both are marked
 * critical, so SubmissionTracker refused every invoice as a duplicate of itself
 * and nothing could be submitted through it at all.
 *
 * The refusal was correct-looking — "Invoice number 'INV-1' already exists" is
 * exactly what a real duplicate produces — which is why it reads as working
 * code. It only shows up when something submits an invoice and watches what
 * comes back.
 */
class DuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_invoice_is_not_self_duplicate(): void
    {
        $invoice = $this->invoice('INV-1');

        $result = $this->check($invoice);

        $this->assertFalse($result['is_duplicate'], 'The invoice was flagged against itself.');
        $this->assertSame([], $result['duplicates']);
    }

    /**
     * The check still has to do its job: a second invoice reusing a number is
     * what ZATCA rejects, and catching it here is the point of the service.
     */
    public function test_a_reused_number_is_a_duplicate(): void
    {
        $this->invoice('INV-1');
        $second = $this->invoice('INV-1');

        $result = $this->check($second);

        $this->assertTrue($result['is_duplicate']);
        $this->assertSame('invoice_number', $result['duplicates'][0]['type']);
    }

    /**
     * A different tenant reusing a number is not a duplicate — invoice numbers
     * are unique per seller, not globally.
     */
    public function test_numbers_are_unique_per_tenant(): void
    {
        $other = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $this->invoice('INV-1');
        $theirs = $this->invoice('INV-1', $other);

        $this->assertFalse($this->check($theirs)['is_duplicate']);
    }

    /**
     * A near-identical invoice raises a warning rather than a refusal, and must
     * not be raised against the invoice itself.
     */
    public function test_fuzzy_match_excludes_itself(): void
    {
        $invoice = $this->invoice('INV-1');

        $warnings = $this->check($invoice)['warnings'];

        $this->assertSame([], $warnings, 'The invoice was flagged as similar to itself.');
    }

    /**
     * @return array{is_duplicate: bool, duplicates: array, warnings: array}
     */
    private function check(Invoice $invoice): array
    {
        return app(DuplicateDetector::class)->check(
            organizationId: $invoice->org_id,
            invoiceNumber: $invoice->invoice_number,
            uuid: $invoice->id,
            hash: $invoice->hash,
            fuzzyMatchData: [
                'buyer_vat' => $invoice->buyer_vat_number,
                'buyer_name' => $invoice->buyer_name,
                'total' => (float) $invoice->total,
                'issue_date' => $invoice->issue_date?->format('Y-m-d'),
            ]
        );
    }

    private function invoice(string $number, ?Organization $organization = null): Invoice
    {
        $organization ??= $this->organization;

        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }
}

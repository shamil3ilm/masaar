<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A taxpayer cannot have two invoices with the same number.
 *
 * This is ZATCA's BT-1 and DuplicateDetector's own docblock states it as a
 * rule. Nothing held it. The schema had a plain index on invoice_number, not
 * a unique one, and the only enforcement was DuplicateDetector reading before
 * writing - with the result cached for an hour, which made the read both
 * racy and stale.
 *
 * It was also enforced in the wrong place. Nothing checks at creation time at
 * all: CreateInvoiceRequest has no unique rule and InvoiceDrafter::draft does
 * not look. DuplicateDetector only runs at submission, so two POSTs with the
 * same number both succeeded and the collision surfaced later as a validation
 * failure - on a document that by then may have been handed to a buyer.
 *
 * The index is the enforcement now. It is scoped to the organization, because
 * two taxpayers numbering their own invoices from 1 is normal and only the
 * pair has to be unique.
 */
class InvoiceNumberUniqueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_one_taxpayer_cannot_reuse_a_number(): void
    {
        $this->draft($this->acme, 'ACME-1');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->draft($this->acme, 'ACME-1');
    }

    /**
     * Two taxpayers numbering from 1 is not a collision.
     */
    public function test_two_taxpayers_may_share_a_number(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $mine = $this->draft($this->acme, 'INV-1');
        $theirs = $this->draft($rival, 'INV-1');

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame('INV-1', $theirs->invoice_number);
    }

    /**
     * And discarding a draft releases its number.
     *
     * Invoices are hard-deleted - InvoiceDrafter::discard calls delete() and
     * the model has no SoftDeletes - so the index cannot strand a number that
     * a taxpayer corrected and wants to use again. If invoices ever gain soft
     * deletes, this test is what will fail.
     */
    public function test_a_discarded_number_can_be_reused(): void
    {
        $draft = $this->draft($this->acme, 'ACME-2');

        Invoice::withoutTenantScope(fn () => $draft->delete());

        $this->assertSame('ACME-2', $this->draft($this->acme, 'ACME-2')->invoice_number);
    }

    private function draft(Organization $organization, string $number): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'status' => 'draft',
            'issue_date' => '2026-03-01',
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\VatPeriodTracker;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Where an adjustment note's VAT belongs.
 *
 * A credit or debit note raised after the original invoice's period has closed
 * has to be reported in the current period — the closed one has already been
 * filed. Getting this wrong restates a filed return.
 *
 * The check tested for CreditNote alone and returned valid for anything else,
 * so a debit note passed without being looked at, including the reference
 * requirement. Its caller applies it to every document type that requires a
 * billing reference, which is both.
 */
class VatPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    /**
     * A plain invoice is not an adjustment and has nothing to validate.
     */
    public function test_a_standard_invoice_passes(): void
    {
        $result = $this->validate($this->invoice('INV-1', 'invoice'));

        $this->assertTrue($result['valid']);
        $this->assertNull($result['warning']);
    }

    public function test_credit_note_needs_a_reference(): void
    {
        $result = $this->validate($this->invoice('CN-1', 'credit_note'));

        $this->assertFalse($result['valid'], 'A credit note without a reference was accepted.');
    }

    /**
     * The gap: a debit note took the early return and was never examined.
     */
    public function test_debit_note_needs_a_reference(): void
    {
        $result = $this->validate($this->invoice('DN-1', 'debit_note'));

        $this->assertFalse($result['valid'], 'A debit note without a reference was accepted.');
    }

    /**
     * Within the same open period there is nothing to warn about.
     */
    public function test_same_period_adjustment_is_quiet(): void
    {
        $original = $this->invoice('INV-1', 'invoice', now()->toDateString(), status: 'issued');
        $note = $this->invoice('CN-1', 'credit_note', now()->toDateString(), $original->invoice_number);

        $result = $this->validate($note);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['warning']);
    }

    /**
     * Across a closed period it stays valid but says so, and names the period
     * the adjustment belongs in.
     */
    public function test_cross_period_adjustment_warns(): void
    {
        $original = $this->invoice('INV-1', 'invoice', now()->subMonths(3)->toDateString(), status: 'issued');
        $note = $this->invoice('CN-1', 'credit_note', now()->toDateString(), $original->invoice_number);

        $result = $this->validate($note);

        $this->assertTrue($result['valid'], 'A cross-period adjustment is legal, not a refusal.');
        $this->assertNotNull($result['warning'], 'The cross-period adjustment was not flagged.');
        $this->assertSame(now()->format('Y-m'), $result['suggested_period']);
    }

    /**
     * A reference to an invoice that does not exist cannot be validated, and
     * saying so beats reporting the note against a period nobody can check.
     */
    public function test_unknown_reference_is_refused(): void
    {
        $note = $this->invoice('CN-1', 'credit_note', now()->toDateString(), (string) Str::uuid());

        $this->assertFalse($this->validate($note)['valid']);
    }

    /**
     * The reference is the original's invoice number - what the document
     * carries in BillingReference, what the partner guide tells an ERP to
     * send, and what the portal asks for. This looked it up as a row id, so
     * every note referenced the documented way was refused at filing as
     * "original invoice not found".
     */
    public function test_reference_is_the_invoice_number(): void
    {
        $this->invoice('INV-7', 'invoice', now()->toDateString(), status: 'issued');
        $note = $this->invoice('CN-7', 'credit_note', now()->toDateString(), 'INV-7');

        $this->assertTrue($this->validate($note)['valid']);
    }

    /**
     * The lookup ran without the tenant scope, so a reference resolved
     * against any organization's invoices.
     */
    public function test_another_organizations_invoice_is_unknown(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $theirs = $this->invoice('INV-9', 'invoice', now()->toDateString(), status: 'issued', organization: $rival);

        $byNumber = $this->invoice('CN-9', 'credit_note', now()->toDateString(), 'INV-9');
        $byId = $this->invoice('CN-10', 'credit_note', now()->toDateString(), $theirs->id);

        $this->assertFalse($this->validate($byNumber)['valid']);
        $this->assertFalse($this->validate($byId)['valid']);
    }

    /**
     * A draft has no counter, no hash and no standing with the authority;
     * there is nothing yet for a note to correct.
     */
    public function test_an_unissued_original_is_refused(): void
    {
        $this->invoice('INV-3', 'invoice', now()->toDateString());
        $note = $this->invoice('CN-3', 'credit_note', now()->toDateString(), 'INV-3');

        $this->assertFalse($this->validate($note)['valid']);
    }

    /**
     * @return array{valid: bool, warning: ?string, suggested_period: ?string}
     */
    private function validate(Invoice $note): array
    {
        return app(VatPeriodTracker::class)->validateAdjustmentPeriod($note);
    }

    private function invoice(
        string $number,
        string $documentType,
        ?string $issueDate = null,
        ?string $billingRef = null,
        string $status = 'draft',
        ?Organization $organization = null,
    ): Invoice {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => ($organization ?? $this->organization)->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'document_type' => $documentType,
            'status' => $status,
            'issue_date' => $issueDate ?? now()->toDateString(),
            'billing_ref' => $billingRef,
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }
}

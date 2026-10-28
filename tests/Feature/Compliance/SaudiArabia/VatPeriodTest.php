<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\VatPeriodTracker;
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
        $original = $this->invoice('INV-1', 'invoice', now()->toDateString(), status: 'accepted');
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
        $original = $this->invoice('INV-1', 'invoice', now()->subMonths(3)->toDateString(), status: 'accepted');
        $note = $this->invoice('CN-1', 'credit_note', now()->toDateString(), $original->invoice_number);

        $result = $this->validate($note);

        $this->assertTrue($result['valid'], 'A cross-period adjustment is legal, not a refusal.');
        $this->assertNotNull($result['warning'], 'The cross-period adjustment was not flagged.');
        $this->assertSame(now()->format('Y-m'), $result['suggested_period']);
    }

    /**
     * L12, IR Art 40(5): a decrease goes in the return of the period the
     * note is issued in (or the event's, if later) - never back in the
     * original's period, even while that return is not yet due.
     */
    public function test_note_reports_in_its_own_period(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10));
        $original = $this->invoice('INV-1', 'invoice', '2026-09-20', status: 'accepted');
        $note = $this->invoice('CN-1', 'credit_note', '2026-10-10', $original->invoice_number);

        $result = $this->validate($note);

        $this->assertTrue($result['valid']);
        $this->assertSame('2026-10', $result['suggested_period']);
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
        $this->invoice('INV-7', 'invoice', now()->toDateString(), status: 'accepted');
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
        $theirs = $this->invoice('INV-9', 'invoice', now()->toDateString(), status: 'accepted', organization: $rival);

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
     * ZATCA never accepted it, so there is nothing on record to adjust.
     */
    public function test_a_rejected_original_is_refused(): void
    {
        $this->invoice('INV-4', 'invoice', status: 'rejected');
        $note = $this->invoice('CN-4', 'credit_note', billingRef: 'INV-4');

        $this->assertFalse($this->validate($note)['valid']);
    }

    /**
     * A note corrects an invoice, not another note.
     */
    public function test_a_note_cannot_reference_a_note(): void
    {
        $this->invoice('INV-5', 'invoice', status: 'accepted');
        $this->invoice('CN-5', 'credit_note', billingRef: 'INV-5', status: 'issued');
        $note = $this->invoice('DN-5', 'debit_note', billingRef: 'CN-5');

        $this->assertFalse($this->validate($note)['valid']);
    }

    /**
     * "The type of credit/debit note follows the type of invoice that they
     * are issued against" (Detailed Guidelines 4.3).
     */
    public function test_note_type_must_match_original(): void
    {
        $this->invoice('INV-6', 'invoice', status: 'accepted');
        $simplified = $this->invoice('CN-6', 'credit_note', billingRef: 'INV-6', type: 'simplified');
        $standard = $this->invoice('CN-7', 'credit_note', billingRef: 'INV-6');

        $this->assertFalse($this->validate($simplified)['valid']);
        $this->assertTrue($this->validate($standard)['valid']);
    }

    /**
     * Credit notes together may not credit more than the invoice charged.
     */
    public function test_credit_beyond_the_original_is_refused(): void
    {
        $this->invoice('INV-8', 'invoice', status: 'accepted');
        $this->invoice('CN-8', 'credit_note', billingRef: 'INV-8', status: 'accepted', total: '100.00');
        $note = $this->invoice('CN-9', 'credit_note', billingRef: 'INV-8', total: '20.00');

        $this->assertFalse($this->validate($note)['valid']);
    }

    /**
     * Up to the total is fine; a rejected or draft note credited nothing.
     */
    public function test_credit_up_to_the_original_passes(): void
    {
        $this->invoice('INV-8', 'invoice', status: 'accepted');
        $this->invoice('CN-8', 'credit_note', billingRef: 'INV-8', status: 'accepted', total: '100.00');
        $this->invoice('CN-10', 'credit_note', billingRef: 'INV-8', status: 'rejected', total: '100.00');
        $this->invoice('CN-11', 'credit_note', billingRef: 'INV-8', total: '100.00');
        $note = $this->invoice('CN-9', 'credit_note', billingRef: 'INV-8', total: '15.00');

        $this->assertTrue($this->validate($note)['valid']);
    }

    /**
     * A debit note adds a charge, so the original's total does not cap it.
     */
    public function test_a_debit_note_is_not_capped(): void
    {
        $this->invoice('INV-8', 'invoice', status: 'accepted');
        $note = $this->invoice('DN-8', 'debit_note', billingRef: 'INV-8', total: '500.00');

        $this->assertTrue($this->validate($note)['valid']);
    }

    /**
     * One note may correct several invoices (BT-25 is text): each must stand,
     * and the credit fits within their combined total.
     */
    public function test_one_note_covers_several_invoices(): void
    {
        $this->invoice('INV-20', 'invoice', status: 'accepted');
        $this->invoice('INV-21', 'invoice', status: 'accepted');

        $within = $this->invoice('CN-20', 'credit_note', billingRef: 'INV-20, INV-21', total: '200.00');
        $this->assertTrue($this->validate($within)['valid']);

        $beyond = $this->invoice('CN-21', 'credit_note', billingRef: 'INV-20; INV-21', total: '231.00');
        $this->assertFalse($this->validate($beyond)['valid']);
    }

    /**
     * A note on one invoice and a note on several share the same originals:
     * what one credited, the other cannot credit again.
     */
    public function test_single_notes_count_against_several(): void
    {
        $this->invoice('INV-20', 'invoice', status: 'accepted');
        $this->invoice('INV-21', 'invoice', status: 'accepted');
        $this->invoice('CN-19', 'credit_note', billingRef: 'INV-20', status: 'accepted', total: '115.00');

        $beyond = $this->invoice('CN-20', 'credit_note', billingRef: 'INV-20, INV-21', total: '116.00');
        $this->assertFalse($this->validate($beyond)['valid']);

        $within = $this->invoice('CN-21', 'credit_note', billingRef: 'INV-20, INV-21', total: '115.00');
        $this->assertTrue($this->validate($within)['valid']);
    }

    public function test_several_count_against_single_notes(): void
    {
        $this->invoice('INV-20', 'invoice', status: 'accepted');
        $this->invoice('INV-21', 'invoice', status: 'accepted');
        $this->invoice('CN-20', 'credit_note', billingRef: 'INV-20, INV-21', status: 'accepted', total: '200.00');

        $beyond = $this->invoice('CN-21', 'credit_note', billingRef: 'INV-20', total: '31.00');
        $this->assertFalse($this->validate($beyond)['valid']);

        $within = $this->invoice('CN-22', 'credit_note', billingRef: 'INV-21', total: '30.00');
        $this->assertTrue($this->validate($within)['valid']);
    }

    /**
     * M11 / A4: a credit sees every note before it, debit notes too - what a
     * debit note added may be credited back, however it named the invoice.
     */
    public function test_debit_notes_raise_the_credit_cap(): void
    {
        $original = $this->invoice('INV-30', 'invoice', status: 'accepted');
        $this->invoice('DN-30', 'debit_note', billingRef: (string) $original->id, status: 'accepted', total: '57.50');

        $this->assertTrue($this->validate($this->invoice('CN-30', 'credit_note', billingRef: 'INV-30', total: '172.50'))['valid']);
        $this->assertFalse($this->validate($this->invoice('CN-31', 'credit_note', billingRef: 'INV-30', total: '172.60'))['valid']);
    }

    /**
     * A debit note on several invoices does not say which it charged more,
     * so it raises their combined cap, never one invoice's alone.
     */
    public function test_shared_debit_raises_combined_cap(): void
    {
        $this->invoice('INV-20', 'invoice', status: 'accepted');
        $this->invoice('INV-21', 'invoice', status: 'accepted');
        $this->invoice('DN-20', 'debit_note', billingRef: 'INV-20, INV-21', status: 'accepted', total: '50.00');

        $this->assertTrue($this->validate($this->invoice('CN-20', 'credit_note', billingRef: 'INV-21; INV-20', total: '280.00'))['valid']);
        $this->assertFalse($this->validate($this->invoice('CN-21', 'credit_note', billingRef: 'INV-20', total: '116.00'))['valid']);
    }

    public function test_every_reference_must_exist(): void
    {
        $this->invoice('INV-20', 'invoice', status: 'accepted');
        $note = $this->invoice('CN-20', 'credit_note', billingRef: 'INV-20, INV-404', total: '10.00');

        $result = $this->validate($note);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('INV-404', (string) $result['warning']);
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
        string $type = 'standard',
        string $total = '115.00',
    ): Invoice {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => ($organization ?? $this->organization)->id,
            'invoice_number' => $number,
            'type' => $type,
            'document_type' => $documentType,
            'status' => $status,
            'issue_date' => $issueDate ?? now()->toDateString(),
            'billing_ref' => $billingRef,
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => $total,
        ]));
    }
}

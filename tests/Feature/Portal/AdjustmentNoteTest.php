<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\AuthorityAnswer;
use Tests\TestCase;

/**
 * Correcting an issued invoice with a note started from it.
 */
class AdjustmentNoteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    /**
     * The form opens with the buyer, the lines and the reference filled in.
     */
    public function test_a_credit_note_starts_filled_in(): void
    {
        $invoice = $this->issued();

        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']))
            ->assertRedirect(route('portal.invoices.create'))
            ->assertSessionHasInput('document_type', 'credit_note')
            ->assertSessionHasInput('billing_ref', 'ACME-1')
            ->assertSessionHasInput('buyer_name', 'Gulf Supplies')
            ->assertSessionHasInput('lines', [[
                'description' => 'Consulting hour',
                'description_ar' => 'ساعة استشارية',
                'class_code' => '',
                'quantity' => '2',
                'unit_price' => '250.00',
                'tax_rate' => '15',
                'tax_category' => 'S',
                'exempt_code' => '',
                'exempt_reason' => '',
                'unit_code' => 'HUR',
            ]]);

        $this->portal()->get(route('portal.invoices.create'))
            ->assertOk()
            ->assertSee('value="ACME-1"', false)
            ->assertSee('Consulting hour');
    }

    /**
     * And saved with a reason, it is a credit note naming the invoice.
     */
    public function test_the_filled_form_saves(): void
    {
        $invoice = $this->issued();
        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']));
        $fields = session()->getOldInput();

        $this->portal()->post('/portal/invoices', [...$fields, 'issue_date' => now()->toDateString(), 'adjustment_reason' => $this->reason()])
            ->assertRedirect();

        $note = Invoice::acrossTenants()->where('document_type', 'credit_note')->firstOrFail();
        $this->assertSame('ACME-1', $note->billing_ref);
        $this->assertSame('500.00', (string) $note->subtotal);
    }

    public function test_a_debit_note_starts_without_lines(): void
    {
        $invoice = $this->issued();

        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'debit_note']))
            ->assertSessionHasInput('document_type', 'debit_note')
            ->assertSessionHasInput('lines', [[]]);
    }

    public function test_a_draft_gets_no_note(): void
    {
        $invoice = $this->issued(['status' => 'draft', 'hash' => null, 'icv' => null]);

        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']))
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('error');
    }

    public function test_a_note_gets_no_note(): void
    {
        $invoice = $this->issued(['document_type' => DocumentType::CreditNote->value, 'billing_ref' => 'ACME-0', 'adjustment_reason' => 'Returned']);

        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']))->assertSessionHas('error');
    }

    /**
     * ZATCA never accepted it, so there is nothing on record to correct.
     */
    public function test_a_rejected_invoice_gets_no_note(): void
    {
        $invoice = $this->issued(['status' => 'rejected']);

        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']))
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('error');
    }

    public function test_a_rejected_invoice_offers_no_note(): void
    {
        $invoice = $this->issued(['status' => 'rejected']);

        $this->portal()->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertDontSee(route('portal.invoices.adjust', [$invoice->id, 'credit_note']));
    }

    public function test_an_accepted_invoice_offers_both_notes(): void
    {
        $invoice = $this->issued(['status' => 'accepted']);

        $this->portal()->get(route('portal.invoices.show', $invoice->id))
            ->assertSee(route('portal.invoices.adjust', [$invoice->id, 'credit_note']))
            ->assertSee(route('portal.invoices.adjust', [$invoice->id, 'debit_note']));
    }

    /**
     * A zero-rated line comes back zero-rated, with its exemption, and the
     * discount comes with the lines - otherwise the note credits a standard
     * line at 0%, which is not what the invoice said, and more than was paid.
     */
    public function test_an_exempt_line_round_trips(): void
    {
        $invoice = $this->issued(['discount_amount' => '50.00', 'total' => '450.00', 'tax_amount' => '0.00'], [
            'tax_rate' => '0',
            'tax_amount' => '0.00',
            'line_total' => '500.00',
            'tax_category' => 'Z',
            'exempt_code' => 'VATEX-SA-32',
            'exempt_reason' => 'Export of goods',
        ]);

        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']))
            ->assertSessionHasInput('discount_amount', '50.00');
        $fields = session()->getOldInput();

        // The line's VAT treatment comes back chosen in its selects.
        $form = (string) $this->portal()->get(route('portal.invoices.create'))->getContent();
        $this->assertMatchesRegularExpression('#<option value="VATEX-SA-32"[^>]*\sselected>#', $form);
        $this->assertMatchesRegularExpression('#<option value="Z"\s+selected>#', $form);

        $this->portal()->post('/portal/invoices', [...$fields, 'issue_date' => now()->toDateString(), 'adjustment_reason' => $this->reason()])
            ->assertSessionHasNoErrors();

        $note = Invoice::acrossTenants()->with('lines')->where('document_type', 'credit_note')->firstOrFail();
        $this->assertSame('50.00', (string) $note->discount_amount);
        $this->assertSame('450.00', (string) $note->total);
        $this->assertSame('Z', $note->lines->first()->tax_category);
        $this->assertSame('VATEX-SA-32', $note->lines->first()->exempt_code);
    }

    /**
     * An export's note is an export's (KSA-2 applies to the associated
     * notes too), and the box the form shows for it is ticked.
     */
    public function test_an_export_note_stays_export(): void
    {
        $invoice = $this->issued(['type' => 'standard', 'is_export' => true]);

        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']))
            ->assertSessionHasInput('is_export', '1');

        $this->assertNull(session()->getOldInput('is_summary'));

        $this->portal()->get(route('portal.invoices.create'))
            ->assertSee('name="is_export" value="1" checked', false);
    }

    /**
     * A new row's carried fields are blank, and a blank is absent: the line
     * is categorised from its rate as one typed by hand always was.
     */
    public function test_blank_carried_fields_are_absent(): void
    {
        $this->portal()->post('/portal/invoices', [
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'lines' => [[
                'description' => 'Tea',
                'description_ar' => 'شاي',
                'quantity' => '1',
                'unit_price' => '10',
                'tax_rate' => '15',
                'tax_category' => '',
                'exempt_code' => '',
                'exempt_reason' => '',
                'class_code' => '',
            ]],
        ])->assertSessionHasNoErrors();

        $line = Invoice::acrossTenants()->with('lines')->firstOrFail()->lines->first();
        $this->assertSame('S', $line->tax_category);
        $this->assertNull($line->exempt_code);
    }

    /**
     * The form offers the reasons ZATCA's data dictionary lists (KSA-10), in
     * both languages as written there, and takes nothing else.
     */
    public function test_the_form_offers_the_official_reasons(): void
    {
        $page = $this->portal()->get(route('portal.invoices.create'))->assertOk();

        foreach (AdjustmentReason::values() as $reason) {
            $page->assertSee($reason);
        }
    }

    public function test_an_unlisted_reason_is_refused(): void
    {
        $invoice = $this->issued();
        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']));
        $fields = session()->getOldInput();

        $this->portal()->post('/portal/invoices', [...$fields, 'issue_date' => now()->toDateString(), 'adjustment_reason' => 'Goods returned'])
            ->assertSessionHasErrors('adjustment_reason');
    }

    public function test_a_viewer_cannot_start_one(): void
    {
        $invoice = $this->issued();
        $this->acme->users()->updateExistingPivot($this->member->id, ['role' => 'viewer']);

        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']))->assertForbidden();
    }

    public function test_another_company_cannot_start_one(): void
    {
        $invoice = $this->issued();
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $outsider = User::factory()->create();
        $outsider->organizations()->attach($rival->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($outsider)->withSession(['portal_org_id' => $rival->id])
            ->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']))
            ->assertNotFound();
    }

    private function reason(): string
    {
        return AdjustmentReason::values()[3];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $line
     */
    private function issued(array $overrides = [], array $line = []): Invoice
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-1',
            'type' => 'simplified',
            'document_type' => $overrides['document_type'] ?? 'invoice',
            'billing_ref' => $overrides['billing_ref'] ?? null,
            'adjustment_reason' => $overrides['adjustment_reason'] ?? null,
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Gulf Supplies',
            'buyer_name_ar' => 'الخليج للتوريدات',
            'subtotal' => '500.00',
            'tax_amount' => '75.00',
            'total' => '575.00',
        ]);

        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting hour',
            'description_ar' => 'ساعة استشارية',
            'quantity' => '2',
            'unit_code' => 'HUR',
            'unit_price' => '250.00',
            'net_amount' => '500.00',
            'tax_rate' => '15',
            'tax_amount' => '75.00',
            'tax_category' => 'S',
            'line_total' => '575.00',
            ...$line,
        ]);

        AuthorityAnswer::record($invoice, $overrides['status'] ?? 'issued', array_merge(
            ['icv' => 1, 'hash' => base64_encode('h'), 'qr_code' => base64_encode('q')],
            array_diff_key($overrides, array_flip(['document_type', 'billing_ref', 'adjustment_reason', 'status'])),
        ));

        return $invoice->fresh(['lines']);
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }
}

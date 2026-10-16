<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'description_ar' => '',
                'quantity' => '2',
                'unit_price' => '250.00',
                'tax_rate' => '15',
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

        $this->portal()->post('/portal/invoices', [...$fields, 'issue_date' => now()->toDateString(), 'adjustment_reason' => 'Goods returned'])
            ->assertRedirect();

        $note = Invoice::withoutTenantScope(fn () => Invoice::query()->where('document_type', 'credit_note')->firstOrFail());
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issued(array $overrides = []): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
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
            'subtotal' => '500.00',
            'tax_amount' => '75.00',
            'total' => '575.00',
        ]));

        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting hour',
            'quantity' => '2',
            'unit_code' => 'HUR',
            'unit_price' => '250.00',
            'net_amount' => '500.00',
            'tax_rate' => '15',
            'tax_amount' => '75.00',
            'line_total' => '575.00',
        ]);

        $invoice->forceFill(array_merge(
            ['status' => 'issued', 'icv' => 1, 'hash' => base64_encode('h'), 'qr_code' => base64_encode('q')],
            array_diff_key($overrides, array_flip(['document_type', 'billing_ref', 'adjustment_reason'])),
        ))->save();

        return $invoice->fresh(['lines']);
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }
}

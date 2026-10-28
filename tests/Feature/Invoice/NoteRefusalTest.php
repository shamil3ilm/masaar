<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Models\ApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A note whose original ZATCA has not accepted yet, said so before anything
 * is spent on it.
 *
 * The walk issued a credit note against an invoice still waiting to be sent
 * and read "Compliance generation failed: Note draft 01a17e19-... cannot be
 * issued: Original invoice ... is issued, not yet accepted by ZATCA" - in
 * English on the Arabic page. With note approvals on, the same note was sent
 * to an approver, who would meet that error in turn.
 */
class NoteRefusalTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'note-refusal';

    private Organization $acme;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $code = app(JurisdictionRegistry::class)->defaultCode();
        app(JurisdictionAvailability::class)->set($code, true);

        $this->acme = Organization::create([
            'name' => 'Acme', 'country' => $code, 'status' => Organization::STATUS_ACTIVE,
            'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $this->owner = $this->person('owner');
    }

    public function test_waiting_original_refuses_the_note(): void
    {
        $note = $this->note('issued');

        $this->issue($this->owner, $note)->assertSessionHas('error', function (string $message) use ($note): bool {
            $this->assertStringContainsString('This credit note cannot be issued yet, so it stays a draft. It corrects invoice ACME-1.', $message);
            $this->assertStringContainsString('ZATCA has not accepted this invoice yet. A note can be made once it is reported.', $message);
            $this->assertStringNotContainsString($note->id, $message);
            $this->assertStringNotContainsString('Compliance generation failed', $message);

            return true;
        });

        $this->assertSame(InvoiceStatus::Draft, $note->fresh()->status);
    }

    public function test_refused_note_is_not_sent_for_approval(): void
    {
        app(Customizations::class)->set('approvals.notes', Level::Organization, Scope::organization((string) $this->acme->id), true, null);
        $this->person('admin');
        $note = $this->note('issued');

        $this->issue($this->person('member'), $note)->assertSessionHas('error');

        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_arabic_refusal_reads_in_arabic(): void
    {
        $note = $this->note('issued');

        $this->asRequest(fn (): TestResponse => $this->portal($this->owner, 'ar')->post(route('portal.invoices.issue', $note->id), ['_token' => self::TOKEN]))
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertDoesNotMatchRegularExpression('/[a-z]/', $message);

                return true;
            });
    }

    public function test_note_form_closed_for_waiting_original(): void
    {
        $note = $this->note('issued');
        $original = Invoice::withoutTenantScope(fn () => Invoice::query()->where('invoice_number', $note->billing_ref)->sole());

        $this->asRequest(fn (): TestResponse => $this->portal($this->owner)->get(route('portal.invoices.adjust', [$original->id, 'credit_note'])))
            ->assertRedirect(route('portal.invoices.show', $original->id))
            ->assertSessionHas('error', __('ZATCA has not accepted this invoice yet. A note can be made once it is reported.'));

        $this->asRequest(fn (): TestResponse => $this->portal($this->owner)->get(route('portal.invoices.show', $original->id)))
            ->assertOk()
            ->assertSee('data-note-wait', false)
            ->assertDontSee(route('portal.invoices.adjust', [$original->id, 'credit_note']));
    }

    public function test_draft_note_page_hides_issue(): void
    {
        $note = $this->note('issued');

        $this->asRequest(fn (): TestResponse => $this->portal($this->owner)->get(route('portal.invoices.show', $note->id)))
            ->assertOk()
            ->assertSee('data-note-refusal', false)
            ->assertDontSee(route('portal.invoices.issue', $note->id));
    }

    /** H-78: the dialog and the panel name a note as a note, without the chain's jargon. */
    public function test_issue_dialog_names_the_note(): void
    {
        $note = $this->note('accepted');

        $this->asRequest(fn (): TestResponse => $this->portal($this->owner)->get(route('portal.invoices.show', $note->id)))
            ->assertOk()
            ->assertSee(e(__('Issue this credit note to :buyer?', ['buyer' => "\u{2068}Gulf Supplies\u{2069}"])), false)
            ->assertDontSee('Issue this invoice')
            ->assertSee(__('This credit note is still a draft. It gets its number and QR code when it is issued.'))
            ->assertDontSee('no counter, no hash');
    }

    public function test_held_note_says_send_for_approval(): void
    {
        app(Customizations::class)->set('approvals.notes', Level::Organization, Scope::organization((string) $this->acme->id), true, null);
        $note = $this->note('accepted');

        $this->asRequest(fn (): TestResponse => $this->portal($this->owner)->get(route('portal.invoices.show', $note->id)))
            ->assertOk()
            ->assertSee(__('Send this credit note for approval?'))
            ->assertSee(__('Send for approval'))
            ->assertSee(__('It goes to another owner or admin to approve. It is numbered and filed only once approved, and stays a draft until then.'))
            ->assertDontSee(__('It is numbered, signed and recorded for ZATCA. This cannot be undone.'));
    }

    private function person(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    /** A draft credit note against ACME-1, which is in the status given. */
    private function note(string $originalStatus): Invoice
    {
        $currency = app(JurisdictionRegistry::class)->get((string) $this->acme->country)->currency();
        $original = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id, 'invoice_number' => 'ACME-1', 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => $originalStatus, 'issue_date' => now()->subDay()->toDateString(), 'currency' => $currency,
            'hash' => base64_encode(hash('sha256', 'ACME-1', true)), 'icv' => 1,
            'subtotal' => '500.00', 'tax_amount' => '75.00', 'total' => '575.00',
        ]));

        $note = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id, 'type' => 'simplified', 'document_type' => 'credit_note',
            'billing_ref' => $original->invoice_number, 'adjustment_reason' => AdjustmentReason::values()[3],
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => $currency,
            'buyer_name' => 'Gulf Supplies', 'buyer_name_ar' => 'الخليج للتوريدات',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));

        InvoiceLine::query()->create([
            'invoice_id' => $note->id, 'description' => 'Returned hour', 'description_ar' => 'ساعة مرتجعة', 'quantity' => '1',
            'unit_code' => 'HUR', 'unit_price' => '100.00', 'net_amount' => '100.00', 'tax_rate' => '15', 'tax_amount' => '15.00',
            'tax_category' => 'S', 'line_total' => '115.00',
        ]);

        return $note->fresh();
    }

    private function portal(User $user, string $locale = 'en'): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id, '_token' => self::TOKEN, 'locale' => $locale]);
    }

    private function issue(User $user, Invoice $note): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->portal($user)
            ->post(route('portal.invoices.issue', $note->id), ['_token' => self::TOKEN]));
    }
}

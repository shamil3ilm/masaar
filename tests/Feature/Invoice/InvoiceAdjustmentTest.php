<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\VatReturn as SaudiReturn;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Invoice\Enums\AdjustmentKind;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceAdjustment;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Models\ApprovalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MatchesOpenapi;
use Tests\TestCase;

/**
 * Return, exchange or replace on an issued Saudi invoice, worked by hand.
 *
 * The original, standard and accepted, issued two months ago:
 *
 *   1  Office chair  4 x 250.00 = 1,000.00, VAT 15% 150.00
 *   2  Desk          1 x 2,000.00 = 2,000.00, VAT 300.00
 *                    total 3,000.00 + 450.00 = 3,450.00
 *
 * Every document is new, names the original (BR-KSA-56) with KSA-10's
 * reason in both languages (BR-KSA-17), and is standard as the original is
 * (Detailed Guidelines 4.3); the original is never changed.
 */
class InvoiceAdjustmentTest extends TestCase
{
    use MatchesOpenapi;
    use RefreshDatabase;

    private const TOKEN = 'adjust';

    private Organization $acme;

    private User $admin;

    private Invoice $original;

    protected function setUp(): void
    {
        parent::setUp();

        app(JurisdictionAvailability::class)->set('SA', true);
        $this->acme = Organization::create([
            'name' => 'Acme', 'country' => 'SA', 'status' => Organization::STATUS_ACTIVE,
            'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $this->admin = $this->person('admin');
        $this->original = $this->original();
    }

    /** 3,450.00 credited; in this month's return, -3,000 / -450 as an adjustment. */
    public function test_full_return_credits_whole_invoice(): void
    {
        $hash = $this->original->hash;

        $this->adjust(['kind' => 'return'])->assertSessionHas('success');

        $note = $this->made(DocumentType::CreditNote);
        $this->assertSame(['3000.00', '450.00', '3450.00'], [(string) $note->subtotal, (string) $note->tax_amount, (string) $note->total]);
        $this->assertSame(['INV-100', 'standard'], [$note->billing_ref, $note->type->value]);
        $this->assertSame(AdjustmentReason::bilingual('In case of goods or services refund.'), $note->adjustment_reason);
        $this->assertNotSame(InvoiceStatus::Draft, $note->status);
        $this->assertSame($hash, $this->original->fresh()->hash);
        $this->assertSame($note->id, InvoiceAdjustment::query()->sole()->note_id);

        $box = app(SaudiReturn::class)->draft((string) $this->acme->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()->startOfDay())->box('1');
        $this->assertSame([0.0, -3000.0, -450.0], [$box->amount, $box->adjustment, $box->vat]);
    }

    /** Two chairs back: 2 x 250 = 500 + 75 = 575.00. Three more is refused: two are left. */
    public function test_partial_return_credits_returned_quantity(): void
    {
        $this->adjust(['kind' => 'partial_return', 'lines' => [['line' => 1, 'quantity' => '2']]])->assertSessionHas('success');

        $this->assertSame('575.00', (string) $this->made(DocumentType::CreditNote)->total);

        $this->adjust(['kind' => 'partial_return', 'lines' => [['line' => 1, 'quantity' => '3']]])->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame(1, InvoiceAdjustment::query()->count());
    }

    /** The desk is scratched and kept at 10% off: 200 + 30 = 230.00, the agreed value amended. */
    public function test_discount_credits_damaged_goods(): void
    {
        $this->adjust(['kind' => 'discount', 'discount_by' => 'percent', 'discount_percent' => '10', 'lines' => [['line' => 2, 'chosen' => '1']]])->assertSessionHas('success');

        $note = $this->made(DocumentType::CreditNote);
        $this->assertSame('230.00', (string) $note->total);
        $this->assertSame(AdjustmentReason::bilingual('Amendment of the supply value which is pre-agreed upon between the supplier and consumer'), $note->adjustment_reason);
    }

    /** One chair for an executive chair at 400: 287.50 credited, a draft for 460.00, both linked. */
    public function test_exchange_credits_and_drafts_replacement(): void
    {
        $this->adjust(['kind' => 'exchange', 'lines' => [['line' => 1, 'quantity' => '1']], 'items' => [['description' => 'Executive chair', 'description_ar' => 'كرسي تنفيذي', 'quantity' => '1', 'unit_price' => '400']]])
            ->assertSessionHas('success');

        $note = $this->made(DocumentType::CreditNote);
        $invoice = $this->made(DocumentType::Invoice);
        $this->assertSame('287.50', (string) $note->total);
        $this->assertSame(['460.00', InvoiceStatus::Draft, 'standard'], [(string) $invoice->total, $invoice->status, $invoice->type->value]);
        $adjustment = InvoiceAdjustment::query()->sole();
        $this->assertSame([$note->id, $invoice->id, AdjustmentKind::Exchange], [$adjustment->note_id, $adjustment->new_invoice_id, $adjustment->kind]);
    }

    /** A chair at 250 replaced by one at 400: a debit note for 150 + 22.50 = 172.50. */
    public function test_dearer_replacement_makes_debit_note(): void
    {
        $this->adjust(['kind' => 'price_difference', 'lines' => [['line' => 1, 'quantity' => '1']], 'items' => [['description' => 'Executive chair', 'description_ar' => 'كرسي تنفيذي', 'quantity' => '1', 'unit_price' => '400']]])
            ->assertSessionHas('success');

        $note = $this->made(DocumentType::DebitNote);
        $this->assertSame(['172.50', 'INV-100'], [(string) $note->total, $note->billing_ref]);
        $this->assertSame(AdjustmentReason::bilingual('In case of essential change or amendment in the supply, which leads to the change of the VAT due'), $note->adjustment_reason);
    }

    /** Replaced by one at 200: a credit note for 50 + 7.50 = 57.50. */
    public function test_cheaper_replacement_makes_credit_note(): void
    {
        $this->adjust(['kind' => 'price_difference', 'lines' => [['line' => 1, 'quantity' => '1']], 'items' => [['description' => 'Basic chair', 'description_ar' => 'كرسي عادي', 'quantity' => '1', 'unit_price' => '200']]])
            ->assertSessionHas('success');

        $this->assertSame('57.50', (string) $this->made(DocumentType::CreditNote)->total);
    }

    /** No tax document: an entry on the invoice, with why and how many, audited. */
    public function test_free_replacement_records_no_document(): void
    {
        $this->adjust(['kind' => 'replacement', 'lines' => [['line' => 1, 'quantity' => '1']]])->assertSessionHasErrors('note');

        $this->adjust(['kind' => 'replacement', 'lines' => [['line' => 1, 'quantity' => '1']], 'note' => 'Broken wheel, replaced under warranty'])->assertSessionHas('success');

        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
        $adjustment = InvoiceAdjustment::query()->sole();
        $this->assertSame([AdjustmentKind::Replacement, null], [$adjustment->kind, $adjustment->note_id]);
        // By key: MySQL reorders a JSON object's keys.
        $line = $adjustment->details['lines'][0];
        ksort($line);
        $this->assertSame(['description' => 'Office chair', 'line' => 1, 'quantity' => '1'], $line);
        $this->assertTrue(DB::table('audit_logs')->where('org_id', $this->acme->id)->where('action', 'invoice.adjusted')->exists());

        $this->asRequest(fn () => $this->portal($this->admin)->get(route('portal.invoices.show', $this->original->id))
            ->assertOk()->assertSee('Broken wheel, replaced under warranty')->assertSee(__('Replaced free by the same item')));
    }

    /** The whole invoice credited, and a draft to the buyer as it should be, its lines as they were. */
    public function test_buyer_correction_credits_and_redrafts(): void
    {
        $this->adjust(['kind' => 'buyer_correction', 'buyer' => ['buyer_name' => 'Gulf Supplies Trading Co']])->assertSessionHas('success');

        $note = $this->made(DocumentType::CreditNote);
        $invoice = $this->made(DocumentType::Invoice);
        $this->assertSame(['3450.00', AdjustmentReason::bilingual("In case of change in Seller's or Buyer's information")], [(string) $note->total, $note->adjustment_reason]);
        $this->assertSame(['Gulf Supplies Trading Co', '3450.00', InvoiceStatus::Draft], [$invoice->buyer_name, (string) $invoice->total, $invoice->status]);
    }

    /** The documents shown with their totals, and nothing made. */
    public function test_preview_shows_documents_makes_nothing(): void
    {
        $this->asRequest(fn () => $this->portal($this->admin)->post(route('portal.invoices.adjustments.preview', $this->original->id), [
            '_token' => self::TOKEN, 'kind' => 'exchange', 'lines' => [['line' => 1, 'quantity' => '1']], 'items' => [['description' => 'Executive chair', 'description_ar' => 'كرسي تنفيذي', 'quantity' => '1', 'unit_price' => '400']],
        ])->assertOk()->assertSee('287.50')->assertSee('460.00')->assertSee('Executive chair'));

        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
        $this->assertSame(0, InvoiceAdjustment::query()->count());
    }

    /** Not yet accepted by ZATCA: nothing to adjust, nothing made, and no button. */
    public function test_unaccepted_original_is_refused(): void
    {
        $this->original = $this->original('issued', 'INV-200');

        $this->asRequest(fn () => $this->portal($this->admin)->get(route('portal.invoices.adjustments.create', $this->original->id))->assertSessionHas('error'));
        $this->adjust(['kind' => 'return'])->assertSessionHas('error');

        $this->assertSame(2, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
        $this->asRequest(fn () => $this->portal($this->admin)->get(route('portal.invoices.show', $this->original->id))->assertOk()->assertDontSee('data-adjust-invoice', false));
    }

    /** Notes held for a second person: the credit note waits, the replacement draft is made. */
    public function test_held_note_waits_for_approval(): void
    {
        app(Customizations::class)->set('approvals.notes', Level::Organization, Scope::organization((string) $this->acme->id), true, null);
        $this->person('owner');

        $this->adjust(['kind' => 'exchange', 'lines' => [['line' => 1, 'quantity' => '1']], 'items' => [['description' => 'Executive chair', 'description_ar' => 'كرسي تنفيذي', 'quantity' => '1', 'unit_price' => '400']]])
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, __('waits for approval')));

        $note = $this->made(DocumentType::CreditNote);
        $this->assertSame(InvoiceStatus::Draft, $note->status);
        $this->assertSame([ApprovalRequest::PENDING, $note->id], [ApprovalRequest::query()->sole()->status, ApprovalRequest::query()->sole()->target_id]);
        $this->assertSame(InvoiceStatus::Draft, $this->made(DocumentType::Invoice)->status);
    }

    public function test_viewer_cannot_adjust_invoice(): void
    {
        $this->asRequest(fn () => $this->portal($this->person('viewer'))->get(route('portal.invoices.adjustments.create', $this->original->id))->assertForbidden());
        $this->adjust(['kind' => 'return'], $this->person('viewer'))->assertForbidden();
    }

    /** One call under an Idempotency-Key; the retry answers the same, and one note is made. */
    public function test_api_adjusts_once_per_key(): void
    {
        License::create([
            'org_id' => $this->acme->id, 'api_key' => 'cp_test_adjust', 'api_secret_hash' => License::hashSecret('secret-adjust'),
            'organization_name' => 'Acme', 'contact_email' => 'ops@acme.test', 'environment' => 'sandbox', 'tier' => 'enterprise',
            'status' => 'active', 'scopes' => ['invoice.read', 'invoice.submit'],
        ]);
        $body = ['kind' => 'partial_return', 'lines' => [['line' => 1, 'quantity' => 2]]];

        $preview = $this->withHeaders(['X-API-Key' => 'cp_test_adjust', 'X-API-Secret' => 'secret-adjust'])
            ->postJson("/api/v1/invoices/{$this->original->id}/adjustments", [...$body, 'preview' => true])->assertOk();
        $this->assertSame('575.00', $preview->json('data.preview.documents.0.total'));
        $this->assertSame(0, InvoiceAdjustment::query()->count());

        $api = $this->withHeaders(['Idempotency-Key' => 'return-1']);

        $first = $api->postJson("/api/v1/invoices/{$this->original->id}/adjustments", $body);
        $this->assertSame(201, $first->status(), (string) $first->getContent());
        $again = $api->postJson("/api/v1/invoices/{$this->original->id}/adjustments", $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.documents.0.invoice_id'), $again->json('data.documents.0.invoice_id'));
        $this->assertSame(['credit', 'issued', 'credit_note'], [$first->json('data.documents.0.role'), $first->json('data.documents.0.status'), $first->json('data.documents.0.document_type')]);
        $this->assertSame(1, InvoiceAdjustment::query()->count());
        $this->assertResponseMatchesSpec($first, '/api/v1/invoices/{invoice}/adjustments', 'post');
    }

    /** @param  array<string, mixed>  $input */
    private function adjust(array $input, ?User $user = null): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->portal($user ?? $this->admin)
            ->post(route('portal.invoices.adjustments.store', $this->original->id), ['_token' => self::TOKEN, ...$input]));
    }

    private function made(DocumentType $type): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->whereKeyNot($this->original->id)->where('document_type', $type->value)->with('lines')->sole());
    }

    private function original(string $status = 'accepted', string $number = 'INV-100'): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id, 'invoice_number' => $number, 'type' => 'standard', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => now()->subMonthsNoOverflow(2)->toDateString(), 'currency' => 'SAR',
            'buyer_name' => 'Gulf Supplies', 'buyer_name_ar' => 'الخليج للتوريدات', 'buyer_vat_number' => '310000000000003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '2345', 'district' => 'Al Malaz', 'city' => 'Riyadh', 'postal_code' => '12211', 'country_code' => 'SA'],
            'subtotal' => '3000.00', 'tax_amount' => '450.00', 'total' => '3450.00',
        ]));

        foreach ([['Office chair', 'كرسي مكتب', '4', '250.00', '1000.00', '150.00'], ['Desk', 'مكتب', '1', '2000.00', '2000.00', '300.00']] as [$name, $arabic, $quantity, $price, $net, $tax]) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id, 'description' => $name, 'description_ar' => $arabic, 'quantity' => $quantity, 'unit_code' => 'PCE',
                'unit_price' => $price, 'net_amount' => $net, 'tax_rate' => '15', 'tax_amount' => $tax, 'tax_category' => 'S',
                'line_total' => bcadd($net, $tax, 2),
            ]);
        }

        $invoice->forceFill(['status' => 'issued', 'icv' => $number === 'INV-100' ? 1 : 2, 'hash' => base64_encode(hash('sha256', $number, true)), 'qr_code' => base64_encode('AQVTZWxsZXI=')])->save();

        if ($status === 'accepted') {
            $invoice->forceFill(['status' => 'accepted'])->save();
        }

        return $invoice->fresh(['lines']);
    }

    private function person(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function portal(User $user): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id, '_token' => self::TOKEN]);
    }
}

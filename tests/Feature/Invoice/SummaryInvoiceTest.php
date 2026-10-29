<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\DeliveryNote;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A month's deliveries to one customer on one summary tax invoice (KSA VAT
 * Implementing Regulations Art 53(4); ZATCA Guideline for Tax Invoicing
 * §4.4, Example 8).
 *
 * Worked example: a building-materials seller delivers to Al Bina
 * Contracting on 3 October (DN-1: 10 bags of cement at 20.00) and on 17
 * October (DN-2: 5 steel bars at 100.00), and again on 2 November. The
 * October summary is one standard tax invoice for 700.00 net, 105.00 VAT at
 * 15%, 805.00 in all, for the period 1-31 October, its lines naming each
 * delivery and its date; it is due by 15 November. The November delivery
 * waits for November's.
 */
class SummaryInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $seller;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Organization::create(['name' => 'Riyadh Building Supplies', 'country' => 'SA', 'vat_number' => '399999999900003']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->seller->id, ['role' => 'admin', 'status' => 'active']);

        $this->customer = new Customer([
            'org_id' => $this->seller->id, 'name' => 'Al Bina Contracting', 'name_ar' => 'البناء للمقاولات',
            'vat_number' => '311111111100003', 'street' => 'King Abdullah Road', 'building_number' => '7788',
            'district' => 'Al Malqa', 'city' => 'Riyadh', 'postal_code' => '13521', 'country_code' => 'SA',
        ]);
        $this->customer->save();
    }

    public function test_a_month_becomes_one_invoice(): void
    {
        $this->deliver('DN-1', '2026-10-03', [['description' => 'Cement bag', 'description_ar' => 'كيس أسمنت', 'quantity' => 10, 'unit_price' => '20.00']])->assertCreated();
        $this->deliver('DN-2', '2026-10-17', [['description' => 'Steel bar', 'description_ar' => 'قضيب حديد', 'quantity' => 5, 'unit_price' => '100.00']])->assertCreated();
        $this->deliver('DN-3', '2026-11-02', [['description' => 'Sand', 'description_ar' => 'رمل', 'quantity' => 1, 'unit_price' => '50.00']])->assertCreated();

        $data = $this->summary('2026-10-01', '2026-10-31')->assertCreated()->json('data');
        $invoice = Invoice::acrossTenants()->with('lines')->findOrFail($data['invoice']['id']);

        $this->assertSame(['standard', true, '2026-10-01', '2026-10-31'], [$invoice->type->value, $invoice->is_summary, $invoice->supply_date->toDateString(), $invoice->supply_end_date->toDateString()]);
        $this->assertSame(['700.00', '105.00', '805.00'], [(string) $invoice->subtotal, (string) $invoice->tax_amount, (string) $invoice->total]);
        $this->assertSame('Cement bag - delivery DN-1 on 2026-10-03', $invoice->lines[0]->description);
        $this->assertStringContainsString('DN-2', (string) $invoice->lines[1]->description_ar);
        $this->assertSame(['DN-1', 'DN-2'], array_column($data['delivery_notes'], 'number'));
        $this->assertSame('2026-11-15', $data['issue_by']);
        $this->assertNull($this->note('DN-3')->invoice_id);
    }

    public function test_a_delivery_is_invoiced_once(): void
    {
        $this->deliver('DN-1', '2026-10-03')->assertCreated();
        $this->summary('2026-10-01', '2026-10-31')->assertCreated();

        $this->summary('2026-10-01', '2026-10-31')->assertUnprocessable()->assertJsonValidationErrors('customer_id', 'error.details');
        $this->withToken($this->token())->deleteJson('/api/delivery-notes/'.$this->note('DN-1')->id)->assertUnprocessable();
    }

    public function test_a_deleted_draft_frees_its_deliveries(): void
    {
        $this->deliver('DN-1', '2026-10-03')->assertCreated();
        $id = $this->summary('2026-10-01', '2026-10-31')->json('data.invoice.id');

        app(InvoiceDrafter::class)->discard(Invoice::acrossTenants()->findOrFail($id));

        $this->assertNull($this->note('DN-1')->invoice_id);
        $this->summary('2026-10-01', '2026-10-31')->assertCreated();
    }

    public function test_a_period_stays_within_one_month(): void
    {
        $this->deliver('DN-1', '2026-10-30')->assertCreated();

        $this->summary('2026-10-15', '2026-11-14')->assertUnprocessable()->assertJsonValidationErrors('to', 'error.details');
    }

    public function test_notes_are_numbered_when_unnamed(): void
    {
        $this->deliver(null, '2026-10-03')->assertCreated()->assertJsonPath('data.delivery_note.number', 'DN-00001');
        $this->deliver('DN-00001', '2026-10-04')->assertUnprocessable()->assertJsonValidationErrors('number', 'error.details');
    }

    public function test_the_portal_drafts_the_month(): void
    {
        $this->deliver('DN-1', '2026-10-03')->assertCreated();

        $this->actingAs($this->admin)->get(route('portal.summaries.index'))->assertOk()->assertSee('DN-1');
        $response = $this->actingAs($this->admin)->post(route('portal.summaries.store'), ['customer_id' => $this->customer->id, 'month' => '2026-10-01', 'issue_date' => '2026-11-03']);

        $invoiceId = $this->note('DN-1')->invoice_id;
        $this->assertNotNull($invoiceId);
        $response->assertRedirect(route('portal.invoices.show', $invoiceId));
    }

    /** N-4: the portal says by when each month's summary is due. */
    public function test_the_portal_shows_issue_by(): void
    {
        $this->travelTo('2026-11-03 10:00:00');
        $this->deliver('DN-1', '2026-10-03')->assertCreated();

        $this->actingAs($this->admin)->get(route('portal.summaries.index'))->assertOk()
            ->assertSee('data-summary-deadline="2026-11-15"', false)
            ->assertSee('data-issue-by="2026-11-15"', false);
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     */
    private function deliver(?string $number, string $date, ?array $lines = null): TestResponse
    {
        return $this->withToken($this->token())->postJson('/api/delivery-notes', array_filter([
            'customer_id' => $this->customer->id,
            'number' => $number,
            'delivered_on' => $date,
            'lines' => $lines ?? [['description' => 'Cement bag', 'description_ar' => 'كيس أسمنت', 'quantity' => 1, 'unit_price' => '20.00']],
        ]));
    }

    private function summary(string $from, string $to): TestResponse
    {
        return $this->withToken($this->token())->postJson('/api/invoices/summary', [
            'customer_id' => $this->customer->id, 'from' => $from, 'to' => $to, 'issue_date' => '2026-11-03',
        ]);
    }

    private function note(string $number): DeliveryNote
    {
        return DeliveryNote::acrossTenants()->where('number', $number)->firstOrFail();
    }

    private function token(): string
    {
        return JWTAuth::claims(['org_id' => $this->seller->id, 'role' => 'admin'])->fromUser($this->admin);
    }
}

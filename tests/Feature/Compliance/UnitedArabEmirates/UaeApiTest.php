<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Networks\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * /api/compliance/ae: drafting, sending and reading UAE e-invoices, for a
 * UAE organization only, and only its own.
 */
class UaeApiTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $this->gulf = $this->uaeOrganization();
    }

    /**
     * Switched off, new work is refused as Saudi Arabia's is, and what was
     * already sent can still be read.
     */
    public function test_switched_off_refuses_new_work(): void
    {
        config(['peppol.countries.AE.enabled' => false]);

        $this->api()->getJson('/api/compliance/ae/documents')->assertOk();
        $this->api()->postJson('/api/compliance/ae/invoices', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JURISDICTION_UNAVAILABLE');
    }

    public function test_draft_is_created_with_uae_fields(): void
    {
        $id = $this->api()->postJson('/api/compliance/ae/invoices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.invoice.status', 'draft')
            ->json('data.invoice.id');

        $invoice = Invoice::query()->with('lines')->findOrFail($id);

        $this->assertSame(['standard', 'invoice', 'AED', '2026-11-09'], [$invoice->type->value, $invoice->document_type->value, $invoice->currency, $invoice->due_date?->toDateString()]);
        $this->assertSame(['1000.00', '50.00', '1050.00'], [$invoice->subtotal, $invoice->tax_amount, $invoice->total]);
        // Sorted: MySQL stores a JSON object's keys in its own order, and
        // nothing reads jurisdiction_data by position.
        $stored = $invoice->jurisdiction_data;
        ksort($stored);
        $this->assertSame([
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'document_category' => 'tax_invoice',
            'transaction_flags' => '00000000',
        ], $stored);
        $this->assertSame(['Consulting', 'S', '5.00'], [$invoice->lines[0]->item_name, $invoice->lines[0]->tax_category, $invoice->lines[0]->tax_rate]);
    }

    /**
     * A line that states no rate takes its category's: the UAE's 5 for a
     * standard line, never the Saudi 15 the shared totals default to; and
     * nothing for a reverse-charge or margin-scheme line, which charge none.
     */
    public function test_missing_rate_is_the_uae_one(): void
    {
        $id = $this->api()->postJson('/api/compliance/ae/invoices', $this->payload([
            'lines' => [$this->line(['tax_rate' => null]), $this->line(['tax_category' => 'Z', 'tax_rate' => null])],
        ]))->assertCreated()->json('data.invoice.id');

        $invoice = Invoice::query()->with('lines')->findOrFail($id);

        $this->assertSame(['5.00', '50.00'], [$invoice->lines[0]->tax_rate, $invoice->lines[0]->tax_amount]);
        $this->assertSame(['0.00', '0.00'], [$invoice->lines[1]->tax_rate, $invoice->lines[1]->tax_amount]);
        $this->assertSame(['50.00', '2050.00'], [$invoice->tax_amount, $invoice->total]);
    }

    /** No debit note, no simplified invoice, no Saudi rate or exemption code. */
    public function test_saudi_shapes_are_refused(): void
    {
        $this->api()->postJson('/api/compliance/ae/invoices', $this->payload(['document_type' => 'debit_note']))
            ->assertUnprocessable()->assertJsonValidationErrorFor('document_type', 'error.details');
        $this->api()->postJson('/api/compliance/ae/invoices', $this->payload(['type' => 'simplified']))
            ->assertUnprocessable()->assertJsonValidationErrorFor('type', 'error.details');
        $this->api()->postJson('/api/compliance/ae/invoices', $this->payload(['lines' => [$this->line(['tax_rate' => 15])]]))
            ->assertUnprocessable()->assertJsonValidationErrorFor('lines.0.tax_rate', 'error.details');
        $this->api()->postJson('/api/compliance/ae/invoices', $this->payload(['lines' => [$this->line(['tax_category' => 'E', 'exempt_code' => 'VATEX-SA-29'])]]))
            ->assertUnprocessable()->assertJsonValidationErrorFor('lines.0.exempt_code', 'error.details');

        $this->assertSame(0, Invoice::query()->count());
    }

    /** A commercial document carries only exempt, out-of-scope and zero-rated lines (IBR-122-AE). */
    public function test_commercial_refuses_standard_lines(): void
    {
        $this->api()->postJson('/api/compliance/ae/invoices', $this->payload([
            'document_category' => 'commercial',
            'buyer_legal_registration' => ['type' => 'TL', 'id' => 'CN-7654321', 'authority' => 'ADDED'],
        ]))->assertUnprocessable()->assertJsonValidationErrorFor('lines.0.tax_category', 'error.details');
    }

    public function test_tax_credit_note_needs_reason(): void
    {
        $this->api()->postJson('/api/compliance/ae/invoices', $this->payload(['document_type' => 'credit_note', 'due_date' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('credit_reason', 'error.details')
            ->assertJsonValidationErrorFor('billing_ref', 'error.details');
    }

    public function test_send_issues_and_queues_once(): void
    {
        $id = $this->draftViaApi();

        $documentId = $this->api()->postJson("/api/compliance/ae/invoices/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.invoice_status', 'issued')
            ->assertJsonPath('data.status', 'ready')
            ->json('data.document_id');

        $this->api()->postJson("/api/compliance/ae/invoices/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.document_id', $documentId);

        $this->assertSame(1, PeppolDocument::query()->count());
        Queue::assertPushed(SendPeppolDocument::class, 1);

        $this->api()->getJson("/api/compliance/ae/invoices/{$id}/status")
            ->assertOk()
            ->assertJsonPath('data.document_id', $documentId)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.message_id', null);
    }

    public function test_send_refuses_with_problems(): void
    {
        $this->gulf->complianceProfileFor('AE')?->update(['settings' => ['trn' => '100123456700003']]);
        $id = $this->draftViaApi();

        $this->api()->postJson("/api/compliance/ae/invoices/{$id}/send")
            ->assertUnprocessable()
            ->assertJsonFragment(['Seller electronic address is missing.']);

        $this->assertSame('draft', Invoice::query()->findOrFail($id)->status->value);
        Queue::assertNothingPushed();
    }

    public function test_documents_list_filter_and_download(): void
    {
        $document = app(PeppolPreparer::class)->prepare($this->uaeInvoice($this->gulf));

        $this->api()->getJson('/api/compliance/ae/documents?direction=out')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonMissingPath('data.0.xml');
        $this->api()->getJson('/api/compliance/ae/documents?direction=in')->assertOk()->assertJsonPath('meta.total', 0);
        $this->api()->getJson('/api/compliance/ae/documents?status=sent')->assertOk()->assertJsonPath('meta.total', 0);
        $this->api()->getJson('/api/compliance/ae/documents?direction=sideways')->assertUnprocessable();

        $this->api()->getJson("/api/compliance/ae/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.document.status', 'ready')
            ->assertJsonMissingPath('data.document.xml');

        $response = $this->api()->get("/api/compliance/ae/documents/{$document->id}/xml")->assertOk();
        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($document->xml, $response->getContent());
    }

    public function test_rival_records_are_not_found(): void
    {
        $rival = $this->uaeOrganization();
        $invoice = $this->uaeInvoice($rival);
        $document = app(PeppolPreparer::class)->prepare($invoice);

        $this->api()->getJson("/api/compliance/ae/documents/{$document->id}")->assertNotFound();
        $this->api()->get("/api/compliance/ae/documents/{$document->id}/xml")->assertNotFound();
        $this->api()->getJson("/api/compliance/ae/invoices/{$invoice->id}/status")->assertNotFound();
        $this->api()->postJson("/api/compliance/ae/invoices/{$invoice->id}/send")->assertNotFound();
        $this->api()->getJson('/api/compliance/ae/documents')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_profile_saves_seller_details(): void
    {
        $this->api()->putJson('/api/compliance/ae/profile', [
            'name' => 'Gulf Trading LLC',
            'street' => 'Al Wasl Road',
            'city' => 'Dubai',
            'emirate' => 'DXB',
            'trn' => '100123456700003',
            'endpoint_id' => '0235:1001234567',
            'legal_registration_type' => 'TL',
            'legal_registration_id' => 'CN-1234567',
            'legal_registration_authority' => 'Dubai Economy and Tourism',
        ])->assertOk()->assertJsonPath('data.settings.tin', '1001234567');

        $this->assertSame('Al Wasl Road', $this->gulf->fresh()->street);
        $this->api()->putJson('/api/compliance/ae/profile', ['endpoint_id' => 'nonsense'])->assertUnprocessable();
        $this->api(role: 'member')->putJson('/api/compliance/ae/profile', [])->assertForbidden();
    }

    private function draftViaApi(): string
    {
        return (string) $this->api()->postJson('/api/compliance/ae/invoices', $this->payload())->assertCreated()->json('data.invoice.id');
    }

    private function api(?Organization $organization = null, string $role = 'admin'): static
    {
        $organization ??= $this->gulf;
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $this->withToken(JWTAuth::claims(['org_id' => $organization->id, 'role' => $role])->fromUser($user));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_filter(array_merge([
            'issue_date' => '2026-10-10',
            'due_date' => '2026-11-09',
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'lines' => [$this->line()],
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'item_name' => 'Consulting',
            'description' => 'Consulting services, October',
            'quantity' => 2,
            'unit_code' => 'DAY',
            'unit_price' => 500,
            'tax_category' => 'S',
            'tax_rate' => 5,
        ], $overrides);
    }
}

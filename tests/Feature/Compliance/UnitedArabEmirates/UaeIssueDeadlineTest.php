<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * MD 243/2025 Art. 6(5): a UAE e-invoice is issued within 14 days of the
 * transaction. A late one is issued and sent all the same, and recorded as
 * late: on its document, in the API's and the pipeline's warnings, and on
 * the portal's invoice page.
 */
class UaeIssueDeadlineTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private const LATE = 'Issued late: more than 14 days after the date of supply.';

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $this->gulf = $this->uaeOrganization();
    }

    public function test_fourteen_days_is_on_time(): void
    {
        $this->travelTo('2026-10-15 12:00:00');

        $document = app(InvoiceIssuer::class)->issue($this->draft(['supply_date' => '2026-10-01']));

        $this->assertFalse($document->issued_late);
    }

    public function test_fifteen_days_is_late(): void
    {
        $this->travelTo('2026-10-16 12:00:00');

        $document = app(InvoiceIssuer::class)->issue($this->draft(['supply_date' => '2026-10-01']));

        $this->assertTrue($document->fresh()->issued_late);
        $this->assertSame('issued', Invoice::query()->findOrFail($document->invoice_id)->status->value);
        $this->assertSame('ready', $document->status);
    }

    /** 21:00 UTC on the 15th is already the 16th in Dubai. */
    public function test_issue_day_is_dubai_date(): void
    {
        $this->travelTo('2026-10-15 21:00:00');

        $document = app(InvoiceIssuer::class)->issue($this->draft(['supply_date' => '2026-10-01']));

        $this->assertTrue($document->issued_late);
    }

    public function test_issue_date_without_supply_date(): void
    {
        $this->travelTo('2026-10-25 12:00:00');

        $document = app(InvoiceIssuer::class)->issue($this->draft(['issue_date' => '2026-10-10']));

        $this->assertTrue($document->issued_late);
    }

    public function test_api_send_and_status_warn(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $invoice = $this->draft(['supply_date' => '2026-10-01']);

        $this->api()->postJson("/api/compliance/ae/invoices/{$invoice->id}/send")
            ->assertOk()
            ->assertJsonPath('data.invoice_status', 'issued')
            ->assertJsonCount(1, 'data.warnings')
            ->assertJsonFragment(['warnings' => [$this->warning()]]);

        $this->api()->getJson("/api/compliance/ae/invoices/{$invoice->id}/status")
            ->assertOk()
            ->assertJsonPath('data.warnings.0', $this->warning());
    }

    public function test_on_time_answers_without_warnings(): void
    {
        $this->travelTo('2026-10-15 12:00:00');
        $invoice = $this->draft(['supply_date' => '2026-10-01']);

        $this->api()->postJson("/api/compliance/ae/invoices/{$invoice->id}/send")
            ->assertOk()
            ->assertJsonPath('data.warnings', []);
    }

    public function test_pipeline_result_carries_the_warning(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $invoice = $this->draft(['supply_date' => '2026-10-01', 'idempotency_key' => 'erp-1']);

        // A retry of the call that drafted it carries on from the draft.
        $result = app(PipelineService::class)->submitInvoice([], $this->gulf->id, idempotencyKey: 'erp-1');

        $this->assertSame($invoice->id, $result['invoice_id']);

        $this->assertSame('issued', $result['status']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([$this->warning()], $result['warnings']);
    }

    public function test_portal_page_shows_late_issue(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $late = app(InvoiceIssuer::class)->issue($this->draft(['supply_date' => '2026-10-01']));
        $onTime = app(InvoiceIssuer::class)->issue($this->draft(['supply_date' => '2026-10-10']));

        $this->portal()->get(route('portal.invoices.show', $late->invoice_id))
            ->assertOk()
            ->assertSee('data-issued-late', false)
            ->assertSee(self::LATE);

        $this->portal()->get(route('portal.invoices.show', $onTime->invoice_id))
            ->assertOk()
            ->assertDontSee('data-issued-late', false);
    }

    /**
     * Issued from the portal, a late invoice is queued, not filed, and the
     * warning is this platform's deadline rule rather than an answer from
     * the authority - so the flash says both.
     */
    public function test_portal_issue_says_queued_and_ours(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $invoice = $this->draft(['supply_date' => '2026-10-01']);

        $flash = $this->issueInPortal($invoice)->assertSessionHas('warning')->getSession()->get('warning');

        $this->assertStringContainsString(__('Issued, and its UAE e-invoice is queued for sending.'), $flash);
        $this->assertStringContainsString(self::LATE, $flash);
        $this->assertStringContainsString('not the authority', $flash);
        $this->assertStringNotContainsString('filed', $flash);
    }

    public function test_portal_issue_on_time_says_queued(): void
    {
        $this->travelTo('2026-10-15 12:00:00');
        $invoice = $this->draft(['supply_date' => '2026-10-01']);

        $this->issueInPortal($invoice)->assertSessionHas('success', __('Issued, and its UAE e-invoice is queued for sending.'));
    }

    /** The same flash in Arabic names the platform's check, not the authority. */
    public function test_portal_late_flash_in_arabic(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $invoice = $this->draft(['supply_date' => '2026-10-01']);

        $flash = $this->issueInPortal($invoice, 'ar')->getSession()->get('warning');

        $this->assertStringContainsString('أجرته المنصة', (string) $flash);
        $this->assertStringContainsString('وليس الجهة المختصة', (string) $flash);
    }

    private function issueInPortal(Invoice $invoice, string $locale = 'en'): TestResponse
    {
        app()->setLocale($locale);

        return $this->portal()->withSession(['portal_org_id' => $this->gulf->id, 'locale' => $locale, '_token' => 'f-token'])
            ->post(route('portal.invoices.issue', $invoice->id), ['_token' => 'f-token'])
            ->assertRedirect(route('portal.invoices.show', $invoice->id));
    }

    private function warning(): string
    {
        return self::LATE.' A UAE e-invoice is issued and sent within 14 days of the transaction (Ministerial Decision No. 243 of 2025, Article 6).';
    }

    /**
     * An unnumbered draft, as the API drafts one.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function draft(array $attributes): Invoice
    {
        $invoice = $this->uaeInvoice($this->gulf, ['invoice_number' => null, ...$attributes, 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00'], issued: false);

        return Invoice::query()->findOrFail($invoice->id);
    }

    private function api(): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->gulf->id, ['role' => 'admin', 'status' => 'active']);

        return $this->withToken(JWTAuth::claims(['org_id' => $this->gulf->id, 'role' => 'admin'])->fromUser($user));
    }

    private function portal(): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->gulf->id, ['role' => 'admin', 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->gulf->id]);
    }
}

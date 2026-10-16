<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Compliance\Fatoora\Services\InvoiceIssuer;
use App\Domains\Compliance\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A UAE organization's invoice reaches UAE issuing from every way in, and
 * ZATCA's issuing refuses it outright.
 */
class UaeRoutingTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
    }

    public function test_zatca_issuing_refuses_uae_organization(): void
    {
        $organization = $this->uaeOrganization();
        $invoice = $this->draft($organization);

        $outcome = app(InvoiceIssuer::class)->issue($invoice, $organization, file: false);

        $this->assertFalse($outcome->issued);
        $this->assertStringContainsString('ZATCA issues Saudi invoices only', $outcome->errors[0]);
        $this->assertSame('draft', Invoice::query()->findOrFail($invoice->id)->status->value);
        $this->assertNull(Invoice::query()->findOrFail($invoice->id)->icv);
    }

    public function test_portal_issue_sends_uae_invoice(): void
    {
        $this->mock(DocumentBuilder::class)->shouldNotReceive('generateComplianceData');
        $organization = $this->uaeOrganization();
        $invoice = $this->draft($organization);
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($user)->withSession(['portal_org_id' => $organization->id])
            ->post("/portal/invoices/{$invoice->id}/issue")
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('success');

        $this->assertSame('issued', Invoice::query()->findOrFail($invoice->id)->status->value);
        $this->assertSame(1, PeppolDocument::query()->count());
        Queue::assertPushed(SendPeppolDocument::class, 1);
    }

    /** The ERP's one call: UAE problems come back, and nothing is signed. */
    public function test_pipeline_routes_uae_organization(): void
    {
        $this->mock(DocumentBuilder::class)->shouldNotReceive('generateComplianceData');
        $organization = $this->uaeOrganization();

        $result = app(PipelineService::class)->submitInvoice([
            'type' => 'standard',
            'document_type' => 'invoice',
            'issue_date' => '2026-10-10',
            'currency' => 'AED',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi', 'country_code' => 'AE'],
            'lines' => [['description' => 'Consulting', 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 5, 'unit_code' => 'DAY']],
        ], $organization->id);

        $this->assertSame('draft', $result['status']);
        $this->assertNull($result['hash']);
        $this->assertStringContainsString('Buyer electronic address is missing.', $result['errors'][0]);
    }

    private function draft(Organization $organization): Invoice
    {
        $invoice = $this->uaeInvoice($organization, ['invoice_number' => null]);
        Invoice::query()->whereKey($invoice->id)->update([
            'status' => 'draft', 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00',
        ]);

        return Invoice::query()->findOrFail($invoice->id);
    }
}

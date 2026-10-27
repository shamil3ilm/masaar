<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Demo mode as a person sees it: a UAE invoice handled by the simulator
 * says so beside its status, and one handled by a provider does not.
 */
class SimulatorDemoTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_invoice_page_says_test_mode(): void
    {
        config(['peppol.gateway' => 'simulator']);

        $this->invoicePage()->assertSee('data-simulated', false)->assertSee('Test mode: a service-provider simulator handles this e-invoice.');
    }

    public function test_provider_invoice_page_does_not(): void
    {
        $this->invoicePage()->assertDontSee('data-simulated', false);
    }

    private function invoicePage(): TestResponse
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();

        $organization = $this->uaeOrganization();
        $invoice = $this->uaeInvoice($organization, ['invoice_number' => null, 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00'], issued: false);
        app(InvoiceIssuer::class)->issue(Invoice::query()->findOrFail($invoice->id));

        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        return $this->asRequest(fn () => $this->actingAs($user)
            ->withSession(['portal_org_id' => $organization->id])
            ->get("/portal/invoices/{$invoice->id}")
            ->assertOk());
    }
}

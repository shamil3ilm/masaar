<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * What issuing says is what happened. A simplified invoice is reported by a
 * queued job; where that queue runs the job at once, the invoice was already
 * accepted when the page said "Filing is queued", while a standard invoice
 * filed the same way said "Issued and filed".
 */
class IssueFlashTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'fatoora.endpoints.sandbox' => 'https://zatca.test/sandbox']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, ['privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 's']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_reported_at_once_says_filed(): void
    {
        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);
        $invoice = $this->draft();

        $this->issue($invoice)->assertSessionHas('success', __('Issued and filed with ZATCA.'));

        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
    }

    public function test_reported_with_warnings_says_so(): void
    {
        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => [
            'status' => 'WARNING',
            'warningMessages' => [['type' => 'WARNING', 'code' => 'BR-KSA-F-13', 'category' => 'KSA', 'message' => 'Please recheck the other buyer ID.', 'status' => 'WARNING']],
        ]])]);

        $this->issue($this->draft())->assertSessionHas('warning', __('Issued and filed, with warnings from the authority. They do not stop the invoice; they are listed on it.'));
    }

    private function draft(): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id, 'invoice_number' => 'Q-1', 'type' => 'simplified', 'document_type' => 'invoice', 'status' => 'draft',
            'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));
        $invoice->lines()->create(['description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => '1.000', 'unit_price' => '100.00', 'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00']);

        return $invoice;
    }

    private function issue(Invoice $invoice): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id, '_token' => 'f-token'])
            ->post(route('portal.invoices.issue', $invoice->id), ['_token' => 'f-token'])
            ->assertRedirect(route('portal.invoices.show', $invoice->id)));
    }
}

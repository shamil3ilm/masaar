<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * BR-KSA-15: a standard tax invoice (388, KSA-2 "01") carries the supply
 * date (KSA-5). The portal neither asked for nor defaulted it, so ZATCA
 * accepted such invoices with a warning. Its form now starts from today, and
 * one issued without it takes its issue date.
 */
class SupplyDateDefaultTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, ['privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 's']);
    }

    public function test_standard_invoice_takes_its_issue_date(): void
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id, 'invoice_number' => 'STD-NOSUPPLY', 'type' => 'standard', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'currency' => 'SAR',
            'buyer_name' => 'Beta Industries', 'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]);
        $invoice->lines()->create(['description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00', 'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00']);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);
        $issued = $invoice->fresh();

        $this->assertSame($issued->issue_date->toDateString(), $issued->supply_date?->toDateString());
        $this->assertStringContainsString('>'.$issued->issue_date->toDateString().'</cbc:ActualDeliveryDate>', (string) $issued->signed_xml);
    }

    public function test_the_form_starts_from_today(): void
    {
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->asRequest(fn () => $this->actingAs($admin)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.invoices.create')))
            ->assertOk()
            ->assertSee('name="supply_date" id="supply_date" value="'.now('Asia/Riyadh')->toDateString().'"', false);
    }
}

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
 * The organization sees each device's certificates, and what still waits to
 * be filed under each.
 *
 * Reached through the Saudi module's own navigation (Presentation), so no
 * shared screen has to know the country.
 */
class CertificatesPanelTest extends TestCase
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
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
    }

    /**
     * After a renewal the old certificate is superseded, and the document it
     * signed that is not yet filed is counted under it.
     */
    public function test_versions_show_with_documents_waiting(): void
    {
        $this->store($this->selfSignedCredentials(serial: 41));
        $this->issued('INV-WAITING-1');
        $this->store($this->selfSignedCredentials(serial: 42));

        $this->asMember('admin')->get('/portal/zatca/certificates')
            ->assertOk()
            ->assertSee('Head office')
            ->assertSeeInOrder(['Active', '0', 'Superseded', '1'])
            ->assertSee('CN=EGS-TEST-0001', false);
    }

    public function test_the_navigation_links_the_panel(): void
    {
        $this->asMember('admin')->get('/portal/zatca')->assertSee(route('portal.zatca.certificates'), false);
    }

    public function test_only_an_administrator_sees_it(): void
    {
        $this->asMember('member')->get('/portal/zatca/certificates')->assertForbidden();
    }

    public function test_the_panel_reads_in_arabic(): void
    {
        $this->store($this->selfSignedCredentials(serial: 43));

        $this->asMember('admin', 'ar')->get('/portal/zatca/certificates')
            ->assertOk()
            ->assertSee('الشهادات');
    }

    /**
     * @param  array{privateKey: string, certificate: string}  $keys
     */
    private function store(array $keys): void
    {
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);
    }

    private function issued(string $number): void
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);
    }

    private function asMember(string $role, string $locale = 'en'): static
    {
        $user = User::factory()->create(['locale' => $locale]);
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}

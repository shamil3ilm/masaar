<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Whether ZATCA can be reached is whether its gateway answers at all.
 *
 * The probe sent HEAD to the environment's base URL and called any 5xx
 * "unreachable". The gateway has nothing at that path and answers 500 -
 * while it files documents fine - so every invoice went to the offline
 * queue, and the owner was told it was filed. An answer of any status is a
 * gateway that is there; only no answer is offline. And it is asked of the
 * organization's own environment.
 */
class ReachabilityTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'fatoora.endpoints.sandbox' => 'https://zatca.test/sandbox',
            'fatoora.endpoints.simulation' => 'https://zatca.test/simulation',
        ]);
    }

    public function test_any_gateway_answer_is_reachable(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertTrue(app(Connectivity::class)->check()['available']);
        $this->assertFalse(app(Connectivity::class)->shouldUseOfflineMode());
    }

    public function test_no_answer_is_offline(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $this->assertTrue(app(Connectivity::class)->shouldUseOfflineMode());
    }

    public function test_detailed_status_reads_the_breaker(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        config(['fatoora.circuit_breaker.timeout_seconds' => 90]);

        $this->assertSame(90, app(Connectivity::class)->getDetailedStatus()['config']['circuit_open_duration_seconds']);
    }

    public function test_it_asks_the_organizations_gateway(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        app(Connectivity::class)->shouldUseOfflineMode('simulation');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://zatca.test/simulation'));
    }

    public function test_a_queued_issue_says_queued(): void
    {
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($acme->id, null, CredentialStore::PCSID, ['privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 's']);
        $admin = User::factory()->create();
        $admin->organizations()->attach($acme->id, ['role' => 'admin', 'status' => 'active']);

        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $acme->id, 'invoice_number' => 'Q-1', 'type' => 'simplified', 'document_type' => 'invoice', 'status' => 'draft',
            'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]);
        $invoice->lines()->create(['description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => '1.000', 'unit_price' => '100.00', 'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00']);

        $this->asRequest(fn () => $this->actingAs($admin)->withSession(['portal_org_id' => $acme->id, '_token' => 'q-token'])
            ->post(route('portal.invoices.issue', $invoice->id), ['_token' => 'q-token'])
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('success', __('Issued. Filing is queued and will happen automatically; the invoice shows when it is accepted.')));
    }
}

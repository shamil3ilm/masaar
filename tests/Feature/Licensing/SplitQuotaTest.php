<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseUsage;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A sale an insurer pays for, against the licence's monthly invoice
 * allowance: every document it files counts, and a call that can file two
 * - the insurer's decision, a credit note and an invoice to the patient -
 * is refused up front unless there is room for both.
 */
class SplitQuotaTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $clinic;

    private License $license;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->clinic = Organization::create([
            'name' => 'Al Shifa Clinic', 'country' => 'SA', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $credentials = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->clinic->id, null, CredentialStore::PCSID, ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']);

        $this->mock(Connectivity::class, fn ($mock) => $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false));
        Http::fake([
            '*/invoices/clearance/*' => Http::response(['clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS', 'infoMessages' => [], 'warningMessages' => [], 'errorMessages' => []], 'clearedInvoice' => base64_encode('<Invoice/>')], 200),
            '*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS', 'infoMessages' => [], 'warningMessages' => [], 'errorMessages' => []]], 200),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    /** The decision's credit note and the patient's invoice each count. */
    public function test_each_document_filed_counts(): void
    {
        $this->licence(10);
        $id = $this->split()->assertCreated()->json('data.split.id');
        $this->assertSame(1, $this->used());

        $this->settle($id)->assertOk();

        $this->assertSame(3, $this->used());
        $this->assertSame(3, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /** One slot left is not room for the two the decision can file. */
    public function test_a_decision_needs_room_for_two(): void
    {
        $this->licence(2);
        $id = $this->split()->assertCreated()->json('data.split.id');

        $this->settle($id)->assertStatus(429);

        $this->assertSame(1, $this->used());
        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * A decision retried under its Idempotency-Key after a timeout is the
     * first one's answer: no second credit note, nothing counted again.
     */
    public function test_a_retried_decision_settles_once(): void
    {
        $this->licence(10);
        $id = $this->split()->assertCreated()->json('data.split.id');
        $this->headers['Idempotency-Key'] = 'settle-1';

        $first = $this->settle($id)->assertOk();
        $this->settle($id)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.split', $first->json('data.split'));

        $this->assertSame(3, $this->used());
        $this->assertSame(3, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    private function licence(int $allowance): void
    {
        $issued = License::createWithCredentials([
            'org_id' => $this->clinic->id, 'organization_name' => 'Al Shifa Clinic', 'contact_email' => 'erp@example.test',
            'tier' => 'professional', 'scopes' => ['invoice.submit'], 'invoices_per_month' => $allowance,
        ]);
        $this->license = $issued['license'];
        $this->headers = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }

    private function split(): TestResponse
    {
        $insurer = new Customer([
            'org_id' => $this->clinic->id, 'name' => 'Gulf Health Insurance', 'name_ar' => 'الخليج للتأمين الصحي',
            'vat_number' => '311111111100003', 'is_insurer' => true, 'street' => 'King Abdullah Road', 'building_number' => '7788',
            'district' => 'Al Malqa', 'city' => 'Riyadh', 'postal_code' => '13521', 'country_code' => 'SA',
        ]);
        Customer::withoutTenantScope(fn () => $insurer->save());

        return $this->withHeaders($this->headers)->postJson('/api/v1/pipeline/split', [
            'org_id' => $this->clinic->id,
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'buyer_name' => 'Fahad Al Harbi', 'buyer_name_ar' => 'فهد الحربي', 'buyer_id' => '1047392816', 'buyer_id_scheme' => 'NAT',
            'lines' => [['description' => 'Consultation', 'description_ar' => 'استشارة', 'quantity' => 1, 'unit_price' => '300.00', 'exempt_code' => 'VATEX-SA-HEA']],
            'split' => ['recipient' => 'insurer', 'insurer_customer_id' => $insurer->id],
        ]);
    }

    private function settle(string $id): TestResponse
    {
        return $this->withHeaders($this->headers)->postJson("/api/v1/invoices/splits/{$id}/settle", ['approved' => '100.00', 'decision' => 'not_covered']);
    }

    private function used(): int
    {
        return (int) LicenseUsage::where('license_id', $this->license->id)->sum('invoices_submitted');
    }
}

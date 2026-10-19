<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A sale sent through the API or the pipeline names the till that made it:
 * by its id, or by its branch and till number. The invoice is then issued
 * on that till - its counter, chain and certificate - and nowhere else.
 */
class TillSelectionTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private Branch $olaya;

    private Branch $malqa;

    private EgsUnit $second;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Pharmacy',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $this->olaya = $this->branch('Olaya');
        $this->malqa = $this->branch('Malqa');
        $this->second = app(Tills::class)->add($this->olaya);

        foreach ([null, $this->olaya->id, $this->second->id] as $unit) {
            $keys = $this->selfSignedCredentials();
            app(CredentialStore::class)->put($this->acme->id, $unit, CredentialStore::PCSID, [
                'privateKey' => $keys['privateKey'],
                'pcsid' => $keys['certificate'],
                'secret' => 'secret',
            ]);
        }
    }

    public function test_api_draft_names_its_till(): void
    {
        $response = $this->api(['till_id' => $this->second->id])->assertCreated();

        $invoice = $this->invoice((string) $response->json('data.invoice.id'));
        $this->assertSame($this->second->id, $invoice->device_id);
        $this->assertSame($this->olaya->id, $invoice->branch_id, 'the till\'s branch is the invoice\'s');
        $this->assertNull($invoice->icv);
    }

    public function test_api_names_branch_and_till_number(): void
    {
        $response = $this->api(['branch_id' => $this->olaya->id, 'till_number' => $this->second->till_number])->assertCreated();

        $this->assertSame($this->second->id, $this->invoice((string) $response->json('data.invoice.id'))->device_id);
    }

    public function test_till_of_another_branch_is_refused(): void
    {
        $this->api(['branch_id' => $this->malqa->id, 'till_id' => $this->second->id])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    public function test_retired_till_is_refused(): void
    {
        $this->second->forceFill(['status' => EgsUnit::RETIRED])->save();

        $this->api(['till_id' => $this->second->id])->assertUnprocessable();
    }

    public function test_pipeline_issues_on_the_named_till(): void
    {
        License::create([
            'org_id' => $this->acme->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret('test-secret-acme'),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@masaar.test',
            'environment' => 'sandbox',
            'tier' => 'starter',
            'status' => 'active',
            'scopes' => ['invoice.submit', 'compliance.status'],
        ]);

        $this->withHeaders(['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => 'test-secret-acme'])
            ->postJson('/api/v1/pipeline/submit', [
                'org_id' => $this->acme->id,
                'branch_id' => $this->olaya->id,
                'till_number' => $this->second->till_number,
                'type' => 'simplified',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'auto_submit' => false,
                'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ])->assertSuccessful();

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->firstOrFail());
        $this->assertSame($this->second->id, $invoice->device_id);
        $this->assertSame(1, $invoice->icv);
    }

    /**
     * Each till numbers from a series of its own, set on the numbering screen.
     */
    public function test_numbering_offers_each_till(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $portal = $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);

        $portal->get(route('portal.numbering.edit', ['branch' => $this->second->id]))
            ->assertOk()
            ->assertSee(__('Till :number - :name', ['number' => $this->second->till_number, 'name' => 'Olaya']))
            ->assertSee('INV-T03-');
    }

    private function api(array $fields): TestResponse
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);

        return $this->withToken($token)->postJson('/api/invoices', [
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ...$fields,
        ]);
    }

    private function invoice(string $id): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($id));
    }

    private function branch(string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id,
            'name' => $name,
            'device_serial' => 'EGS-'.$name,
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => $name,
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'onboarding_status' => 'active',
        ]));
    }
}

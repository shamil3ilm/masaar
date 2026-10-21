<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * What ZATCA advises while accepting an invoice reaches the person and the
 * integrator, and is never dropped.
 *
 * The clearance and reporting APIs answer 202 when a document is accepted
 * with warnings, and put them under validationResults.warningMessages, each
 * with its rule code (ZATCA Developer Portal, Reporting and Clearance APIs).
 * They were read from the top level, where ZATCA puts none, so every warning
 * was lost before anything could store it.
 */
class AdvisoryWarningTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const ADVICE = 'BR-KSA-F-13: Please recheck Other Seller/Buyer ID (BT-29 or BT-46) as it contains an invalid value.';

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $credentials = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']);

        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->mock(Connectivity::class, fn ($mock) => $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false));

        $warnings = ['status' => 'WARNING', 'infoMessages' => [], 'errorMessages' => [], 'warningMessages' => [[
            'type' => 'WARNING', 'code' => 'BR-KSA-F-13', 'category' => 'KSA', 'status' => 'WARNING',
            'message' => 'Please recheck Other Seller/Buyer ID (BT-29 or BT-46) as it contains an invalid value.',
        ]]];

        Http::fake([
            '*/invoices/clearance/*' => Http::response(['clearanceStatus' => 'CLEARED', 'validationResults' => $warnings, 'clearedInvoice' => base64_encode('<Invoice/>')], 202),
            '*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => $warnings], 202),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_the_api_submit_lists_them(): void
    {
        $invoice = $this->issued('ACME-1');

        $this->withToken($this->token())->postJson('/api/compliance/sa/submit/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.warnings', [self::ADVICE]);

        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
    }

    public function test_the_invoice_keeps_them_afterwards(): void
    {
        $invoice = $this->issued('ACME-2');
        $this->withToken($this->token())->postJson('/api/compliance/sa/submit/'.$invoice->id)->assertOk();

        $this->withToken($this->token())->getJson('/api/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.warnings', [self::ADVICE]);
        $this->withToken($this->token())->getJson('/api/compliance/sa/status/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.warnings', [self::ADVICE]);
    }

    /** The pipeline's answer, and its answer to the same request again. */
    public function test_the_pipeline_lists_them(): void
    {
        $issued = License::createWithCredentials([
            'org_id' => $this->acme->id, 'organization_name' => 'Acme Trading', 'contact_email' => 'erp@example.test',
            'tier' => 'professional', 'scopes' => ['invoice.submit'],
        ]);
        $headers = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
        $payload = [
            'org_id' => $this->acme->id, 'erp_reference_id' => 'SO-1', 'type' => 'standard', 'document_type' => 'invoice',
            'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            'buyer_name' => 'Beta Industries', 'buyer_name_ar' => 'شركة بيتا', 'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
            'lines' => [['description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => 1, 'unit_price' => '100.00']],
        ];

        $this->withHeaders($headers)->postJson('/api/v1/pipeline/submit', $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.warnings', [self::ADVICE]);
        $this->withHeaders($headers)->postJson('/api/v1/pipeline/submit', $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.warnings', [self::ADVICE]);
    }

    public function test_the_portal_issue_says_so(): void
    {
        $invoice = $this->draft('ACME-3');

        $this->asRequest(fn () => $this->portal()->withSession(['_token' => 'advice-token'])->post(route('portal.invoices.issue', $invoice->id), ['_token' => 'advice-token'])
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'warnings from the authority')));

        $this->asRequest(fn () => $this->portal()->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('data-advisories', false)
            ->assertSee(self::ADVICE));
    }

    /**
     * In plain words, in the reader's language, with ZATCA's own text kept
     * beside it: it was shown raw and in English, even in Arabic.
     */
    public function test_the_portal_explains_them_plainly(): void
    {
        $invoice = $this->draft('ACME-4');
        $this->asRequest(fn () => $this->portal()->withSession(['_token' => 'advice-token'])->post(route('portal.invoices.issue', $invoice->id), ['_token' => 'advice-token']));
        $plain = 'ZATCA asks you to check the other identification number of the buyer or seller: it looks like a placeholder, such as a run of repeated or consecutive digits. The invoice was accepted.';

        $this->asRequest(fn () => $this->portal()->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSeeInOrder(['data-advisories', e(__($plain)), 'data-authority-raw', e(self::ADVICE)], false));

        $this->asRequest(fn () => $this->portal()->withSession(['locale' => 'ar'])->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee(__($plain, [], 'ar')));
    }

    private function token(): string
    {
        return JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);
    }

    private function portal(): static
    {
        return $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id]);
    }

    private function draft(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Beta Industries',
            'buyer_name_ar' => 'شركة بيتا',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget',
            'description_ar' => 'قطعة',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    private function issued(string $number): Invoice
    {
        $invoice = $this->draft($number);
        app(Submitter::class)->generate($invoice, $this->acme);

        return $invoice->fresh(['lines']);
    }
}

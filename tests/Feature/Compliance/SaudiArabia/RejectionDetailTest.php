<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
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
 * A rejected invoice says exactly which rule failed.
 *
 * ZATCA refuses a document with HTTP 400 and the reasons under
 * validationResults - each error and warning with its rule code, category
 * and message (ZATCA Developer Portal, Reporting and Clearance APIs). The
 * body here is in that shape (tests/Fixtures/zatca/reporting-rejected.json),
 * the rule texts as ZATCA's own Schematron words them. Only a summary
 * string used to survive; the rules are now kept on the submission and the
 * invoice, and shown on the portal and in the API.
 */
class RejectionDetailTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const RULE = ['code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'The seller address building number must contain 4 digits.'];

    private const ADVICE = ['code' => 'BR-KSA-F-13', 'category' => 'KSA', 'message' => 'Please recheck Other Seller/Buyer ID (BT-29 or BT-46) as it contains an invalid value.'];

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $credentials = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']);

        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $rejected = json_decode((string) file_get_contents(base_path('tests/Fixtures/zatca/reporting-rejected.json')), true);

        // Clearance refuses in the same shape, with its own status.
        $notCleared = ['clearanceStatus' => 'NOT_CLEARED', 'validationResults' => $rejected['validationResults']];

        Http::fake([
            '*/invoices/reporting/*' => Http::response($rejected, 400),
            '*/invoices/clearance/*' => Http::response($notCleared, 400),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_the_api_submit_names_the_rule(): void
    {
        $invoice = $this->issued('ACME-1');

        $this->withToken($this->token())->postJson('/api/compliance/sa/submit/'.$invoice->id)
            ->assertStatus(422)
            ->assertJsonPath('error.details', [self::RULE]);

        $this->assertSame(InvoiceStatus::Rejected, $invoice->fresh()->status);

        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()->where('invoice_id', $invoice->id)->sole());
        // By key: MySQL keeps a JSON object's keys in an order of its own.
        $this->assertEquals(['rejected', [self::RULE], [self::ADVICE]], [$submission->state, $submission->zatca_errors, $submission->zatca_warnings]);
    }

    public function test_the_invoice_keeps_the_rule(): void
    {
        $invoice = $this->issued('ACME-2');
        $this->withToken($this->token())->postJson('/api/compliance/sa/submit/'.$invoice->id)->assertStatus(422);

        $this->withToken($this->token())->getJson('/api/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.rejections', [self::RULE])
            ->assertJsonPath('data.warnings', ['BR-KSA-F-13: '.self::ADVICE['message']]);
        $this->withToken($this->token())->getJson('/api/compliance/sa/status/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.rejections', [self::RULE]);
    }

    public function test_the_portal_page_names_the_rule(): void
    {
        $invoice = $this->issued('ACME-3');
        $this->withToken($this->token())->postJson('/api/compliance/sa/submit/'.$invoice->id)->assertStatus(422);

        $this->asRequest(fn () => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('data-rejections', false)
            ->assertSee('BR-KSA-37')
            ->assertSee(self::RULE['message'])
            ->assertSee('Warnings from the authority'));
    }

    /** A standard invoice the ERP sends through the pipeline, refused at clearance. */
    public function test_the_pipeline_names_the_rule(): void
    {
        $issued = License::createWithCredentials([
            'org_id' => $this->acme->id, 'organization_name' => 'Acme Trading', 'contact_email' => 'erp@example.test',
            'tier' => 'professional', 'scopes' => ['invoice.submit'],
        ]);

        $this->withHeaders(['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']])->postJson('/api/v1/pipeline/submit', [
            'org_id' => $this->acme->id, 'erp_reference_id' => 'SO-9', 'type' => 'standard', 'document_type' => 'invoice',
            'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            'buyer_name' => 'Beta Industries', 'buyer_name_ar' => 'شركة بيتا', 'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
            'lines' => [['description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => 1, 'unit_price' => '100.00']],
        ])->assertJsonPath('data.compliance_status', 'rejected')
            ->assertJsonPath('data.rejections', [self::RULE])
            ->assertJsonPath('data.errors', ['BR-KSA-37: '.self::RULE['message']]);
    }

    private function token(): string
    {
        return JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);
    }

    private function issued(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id, 'invoice_number' => $number, 'type' => 'simplified', 'document_type' => 'invoice', 'status' => 'draft',
            'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));
        $invoice->lines()->create([
            'description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);

        return $invoice->fresh(['lines']);
    }
}

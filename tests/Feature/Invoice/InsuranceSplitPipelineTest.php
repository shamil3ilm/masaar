<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InsuranceSplits;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A sale an insurer pays for, the whole way: drafted, issued and filed in
 * one pipeline call, and then the insurer's decision as ZATCA's General VAT
 * Guideline Healthcare (January 2023) §5.3 has it (p25-26).
 */
class InsuranceSplitPipelineTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $clinic;

    private Customer $insurer;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->clinic = Organization::create([
            'name' => 'Al Shifa Clinic', 'country' => 'SA', 'vat_number' => '300000000000003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $credentials = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->clinic->id, null, CredentialStore::PCSID, ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']);

        $this->insurer = new Customer([
            'org_id' => $this->clinic->id, 'name' => 'Gulf Health Insurance', 'name_ar' => 'الخليج للتأمين الصحي',
            'vat_number' => '311111111100003', 'is_insurer' => true, 'street' => 'King Abdullah Road', 'building_number' => '7788',
            'district' => 'Al Malqa', 'city' => 'Riyadh', 'postal_code' => '13521', 'country_code' => 'SA',
        ]);
        Customer::withoutTenantScope(fn () => $this->insurer->save());

        $this->mock(Connectivity::class, fn ($mock) => $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false));
        Http::fake([
            '*/invoices/clearance/*' => Http::response(['clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS', 'infoMessages' => [], 'warningMessages' => [], 'errorMessages' => []], 'clearedInvoice' => base64_encode('<Invoice/>')], 200),
            '*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS', 'infoMessages' => [], 'warningMessages' => [], 'errorMessages' => []]], 200),
            '*' => Http::response(['ok' => true]),
        ]);

        $issued = License::createWithCredentials([
            'org_id' => $this->clinic->id, 'organization_name' => 'Al Shifa Clinic', 'contact_email' => 'erp@example.test',
            'tier' => 'professional', 'scopes' => ['invoice.submit'],
        ]);
        $this->headers = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }

    public function test_the_invoice_is_issued_and_filed(): void
    {
        $data = $this->submit()->assertCreated()->json('data');

        $this->assertSame('accepted', $data['invoice']['status']);
        $this->assertSame(['patient', '100.00', '400.00'], [$data['split']['recipient'], $data['split']['patient_share'], $data['split']['insurer_share']]);
        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    public function test_a_retry_files_nothing_twice(): void
    {
        $first = $this->submit([], 'split-retry')->assertCreated()->json('data.invoice.invoice_id');

        $this->assertSame($first, $this->submit([], 'split-retry')->assertCreated()->json('data.invoice.invoice_id'));
        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * The patient is the recipient and the insurer pays less: nothing is
     * corrected, the patient owes the rest with its VAT (§5.3.2 p25).
     */
    public function test_the_patient_owes_what_insurers_decline(): void
    {
        $split = $this->split($this->submit()->json('data.split.id'));

        $outcomes = app(InsuranceSplits::class)->settle($split, $this->clinic, '300.00', null, null, new IssueOptions(file: true));
        $split->refresh();

        $this->assertSame(['credit' => null, 'rebill' => null], $outcomes);
        $this->assertSame([InsuranceSplit::SETTLED, InsuranceSplit::NOT_COVERED, '300.00'], [$split->status, $split->decision, $split->insurerOwes()]);
        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * The insurer is the recipient and the care is partly not covered: a
     * credit note on its invoice naming it, and an invoice to the patient,
     * whose citizen's healthcare is zero-rated again (§5.3.2, §5.3.3).
     */
    public function test_uncovered_care_is_billed_to_patients(): void
    {
        $split = $this->split($this->submit($this->toInsurer())->json('data.split.id'));
        $invoice = $split->sale();

        // 500 of value invoiced; the insurer accepts 400 of it.
        app(InsuranceSplits::class)->settle($split, $this->clinic, '400.00', InsuranceSplit::NOT_COVERED, null, new IssueOptions(file: true));
        $split->refresh();

        $credit = $split->creditNote;
        $rebill = $split->rebill;

        $this->assertSame([DocumentType::CreditNote, 'standard', $invoice->invoice_number], [$credit->document_type, $credit->type->value, $credit->billing_ref]);
        $this->assertSame(['100.00', '15.00'], [(string) $credit->subtotal, (string) $credit->tax_amount]);
        $this->assertSame(['simplified', 'rebill', 'NAT', '1047392816'], [$rebill->type->value, $rebill->split_role, $rebill->buyer_id_scheme, $rebill->buyer_id]);
        $this->assertSame('100.00', (string) $rebill->subtotal);
        // The consultation zero-rated for the citizen; the dressing standard-rated as it was.
        $this->assertSame(['Z', 'S'], $rebill->lines->pluck('tax_category')->all());
        $this->assertSame([$credit->lines->pluck('unit_price')->all(), $credit->lines->pluck('description')->all()], [$rebill->lines->pluck('unit_price')->all(), $rebill->lines->pluck('description')->all()]);
    }

    /** Its agreed price (§5.3.1, Example 12 p26): a credit note alone. */
    public function test_an_agreed_price_is_credited_only(): void
    {
        $split = $this->split($this->submit($this->toInsurer())->json('data.split.id'));

        $outcomes = app(InsuranceSplits::class)->settle($split, $this->clinic, '375.00', InsuranceSplit::PRICE, null, new IssueOptions(file: true));
        $split->refresh();

        $this->assertNull($outcomes['rebill']);
        $this->assertNull($split->rebill_invoice_id);
        $this->assertSame('125.00', (string) $split->creditNote->subtotal);
        $this->assertStringStartsWith('Amendment of the supply value', (string) $split->creditNote->adjustment_reason);
        $this->assertSame(bcsub((string) $split->sale()->total, (string) $split->creditNote->total, 2), $split->insurerOwes());
    }

    /** Through the API, with the reason. */
    public function test_the_api_records_the_decision(): void
    {
        $id = $this->submit($this->toInsurer())->json('data.split.id');

        $data = $this->withHeaders($this->headers)->postJson("/api/v1/invoices/splits/{$id}/settle", ['approved' => '450.00', 'decision' => 'not_covered'])
            ->assertOk()
            ->json('data');

        $this->assertSame(['settled', '450.00', 'not_covered'], [$data['split']['status'], $data['split']['insurer_approved'], $data['split']['decision']]);
        $this->assertSame($data['split']['rebill_invoice_id'], $data['invoices']['rebill']['id']);
    }

    public function test_a_decision_is_recorded_once(): void
    {
        $split = $this->split($this->submit()->json('data.split.id'));
        app(InsuranceSplits::class)->settle($split, $this->clinic, '400.00', null, null, new IssueOptions(file: true));

        $this->expectException(ValidationException::class);
        app(InsuranceSplits::class)->settle($split->fresh(), $this->clinic, '0', null, null, new IssueOptions(file: true));
    }

    /**
     * A citizen's consultation and a dressing, the insurer the recipient.
     *
     * @return array<string, mixed>
     */
    private function toInsurer(): array
    {
        return [
            'prices_include_vat' => false,
            'buyer_name' => 'Fahad Al Harbi', 'buyer_name_ar' => 'فهد الحربي', 'buyer_id' => '1047392816', 'buyer_id_scheme' => 'NAT',
            'lines' => [
                ['description' => 'Consultation', 'description_ar' => 'استشارة', 'quantity' => 1, 'unit_price' => '300.00', 'exempt_code' => 'VATEX-SA-HEA'],
                ['description' => 'Dressing', 'description_ar' => 'ضماد', 'quantity' => 2, 'unit_price' => '100.00'],
            ],
            'split' => ['recipient' => 'insurer', 'insurer_customer_id' => $this->insurer->id, 'claim_reference' => 'CLM-2'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submit(array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->withHeaders([...$this->headers, ...($key === null ? [] : ['Idempotency-Key' => $key])])->postJson('/api/v1/pipeline/split', [
            'org_id' => $this->clinic->id,
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'prices_include_vat' => true,
            'buyer_name' => 'Sara Al Qahtani',
            'buyer_name_ar' => 'سارة القحطاني',
            'lines' => [
                ['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => 2, 'unit_price' => '100.00', 'exempt_code' => 'VATEX-SA-35'],
                ['description' => 'Dressing', 'description_ar' => 'ضماد', 'quantity' => 1, 'unit_price' => '300.00'],
            ],
            'split' => ['method' => 'percent', 'percent' => 20, 'insurer_customer_id' => $this->insurer->id, 'claim_reference' => 'CLM-1'],
            ...$overrides,
        ]);
    }

    private function split(string $id): InsuranceSplit
    {
        return InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->with('invoices.lines')->findOrFail($id));
    }
}

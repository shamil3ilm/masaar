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
 * A split sale the whole way: both shares drafted, issued and filed in one
 * pipeline call, and then the insurer's decision - what it declined
 * credited on its invoice and billed to the patient.
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

    public function test_both_shares_are_issued_and_filed(): void
    {
        $data = $this->submit()->assertCreated()->json('data');

        $this->assertSame(['accepted', 'accepted'], [$data['patient']['status'], $data['insurer']['status']]);
        $this->assertNotSame($data['patient']['invoice_number'], $data['insurer']['invoice_number']);

        $patient = $this->invoice($data['patient']['invoice_id']);
        $insurer = $this->invoice($data['insurer']['invoice_id']);

        // Each its own counter in the chain, the patient's first.
        $this->assertSame($patient->icv + 1, $insurer->icv);
        $this->assertSame($patient->hash, $insurer->previous_invoice_hash);
        $this->assertSame('500.00', $data['split']['billed']);
    }

    /** A retry under the same key answers with the same two invoices. */
    public function test_a_retry_files_nothing_twice(): void
    {
        $first = $this->submit('split-retry')->assertCreated()->json('data');
        $again = $this->submit('split-retry')->assertCreated()->json('data');

        $this->assertSame([$first['patient']['invoice_id'], $first['insurer']['invoice_id']], [$again['patient']['invoice_id'], $again['insurer']['invoice_id']]);
        $this->assertSame(2, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * The insurer approves 300 of its 400: the 100 it declined is credited
     * on its invoice and billed to the patient, item for item.
     */
    public function test_a_partial_approval_rebills_the_patient(): void
    {
        $split = $this->split($this->submit()->json('data.split.id'));

        $outcomes = app(InsuranceSplits::class)->settle($split, $this->clinic, '300.00', null, new IssueOptions(file: true));
        $split->refresh();

        $this->assertTrue($outcomes['credit']->issued);
        $this->assertTrue($outcomes['rebill']->issued);
        $this->assertSame(InsuranceSplit::SETTLED, $split->status);

        $credit = $split->creditNote;
        $rebill = $split->rebill;

        $this->assertSame(DocumentType::CreditNote, $credit->document_type);
        $this->assertSame('standard', $credit->type->value);
        $this->assertSame($split->share('insurer')->invoice_number, $credit->billing_ref);
        $this->assertSame(['simplified', 'rebill'], [$rebill->type->value, $rebill->split_role]);
        $this->assertSame(['100.00', '100.00'], [(string) $credit->total, (string) $rebill->total]);
        $this->assertSame($this->items($credit), $this->items($rebill));

        // The sale is still billed in full: the patient now pays 200.
        $this->assertSame('500.00', $split->fresh()->billed());
    }

    public function test_a_rejection_credits_everything(): void
    {
        $split = $this->split($this->submit()->json('data.split.id'));

        app(InsuranceSplits::class)->settle($split, $this->clinic, '0', null, new IssueOptions(file: true));
        $split->refresh();

        $this->assertSame((string) $split->share('insurer')->total, (string) $split->creditNote->total);
        $this->assertSame('400.00', (string) $split->rebill->total);
        $this->assertSame('500.00', $split->fresh()->billed());
    }

    /** Through the API: the decision, and the documents it issued, in the answer. */
    public function test_the_api_records_the_decision(): void
    {
        $id = $this->submit()->json('data.split.id');

        $data = $this->withHeaders($this->headers)->postJson("/api/v1/invoices/splits/{$id}/settle", ['approved' => '350.00'])
            ->assertOk()
            ->json('data');

        $this->assertSame(['settled', '350.00', '500.00'], [$data['split']['status'], $data['split']['insurer_approved'], $data['split']['billed']]);
        $this->assertSame('50.00', $data['invoices']['rebill']['total']);
        $this->assertSame($data['split']['rebill_invoice_id'], $data['invoices']['rebill']['id']);
    }

    public function test_a_decision_is_recorded_once(): void
    {
        $split = $this->split($this->submit()->json('data.split.id'));
        app(InsuranceSplits::class)->settle($split, $this->clinic, '400.00', null, new IssueOptions(file: true));

        $this->expectException(ValidationException::class);
        app(InsuranceSplits::class)->settle($split->fresh(), $this->clinic, '0', null, new IssueOptions(file: true));
    }

    private function submit(?string $key = null): TestResponse
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
        ]);
    }

    private function split(string $id): InsuranceSplit
    {
        return InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->findOrFail($id));
    }

    private function invoice(string $id): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($id));
    }

    /** @return list<array{string, string, string, string}> */
    private function items(Invoice $invoice): array
    {
        return $invoice->lines->map(fn ($line): array => [(string) $line->description, (string) $line->quantity, (string) $line->unit_price, (string) $line->tax_category])->all();
    }
}

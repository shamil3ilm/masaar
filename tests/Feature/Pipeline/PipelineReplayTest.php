<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A retry under the same Idempotency-Key answers as the first call did.
 *
 * A simplified invoice is reported by a queued job. Where the queue runs it
 * at once, the first call answered from the invoice as it was before the
 * job - 201 "submitted" for an invoice the authority had just rejected -
 * and the retry, reading the record, answered 207 with the rejection.
 */
class PipelineReplayTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    /** @var array<string, string> */
    private array $credential;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'fatoora.endpoints.sandbox' => 'https://zatca.test/sandbox']);

        $this->acme = Organization::create([
            'name' => 'Acme', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, ['privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 's']);

        License::create([
            'org_id' => $this->acme->id, 'api_key' => 'cp_test_acme', 'api_secret_hash' => License::hashSecret('test-secret-acme'),
            'organization_name' => 'Acme', 'contact_email' => 'ops@example.test', 'environment' => 'sandbox',
            'tier' => 'starter', 'status' => 'active', 'scopes' => ['invoice.submit', 'compliance.status'],
        ]);
        $this->credential = ['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => 'test-secret-acme'];
    }

    public function test_reported_replay_answers_the_same(): void
    {
        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);

        $this->assertSameAnswer($this->submit()->assertCreated(), $this->submit());
    }

    public function test_rejected_replay_answers_the_same(): void
    {
        Http::fake(['*' => Http::response(['reportingStatus' => 'NOT_REPORTED', 'validationResults' => [
            'status' => 'ERROR',
            'errorMessages' => [['type' => 'ERROR', 'code' => 'certificate-permissions', 'category' => 'CERTIFICATE_ERRORS', 'message' => 'User only allowed to use the vat number that exists in the authentication certificate', 'status' => 'ERROR']],
        ]], 400)]);

        $first = $this->submit()->assertStatus(207);

        $this->assertSame('rejected', $first->json('data.status'));
        $this->assertSameAnswer($first, $this->submit());
    }

    /**
     * A note is refused against a rejected original - notes follow an
     * accepted one only - so the rejection points at a new invoice.
     */
    public function test_rejection_says_issue_new_invoice(): void
    {
        Http::fake(['*' => Http::response(['reportingStatus' => 'NOT_REPORTED', 'validationResults' => [
            'status' => 'ERROR',
            'errorMessages' => [['type' => 'ERROR', 'code' => 'BR-KSA-40', 'category' => 'KSA', 'message' => 'Seller VAT number is invalid', 'status' => 'ERROR']],
        ]], 400)]);

        $this->submit()->assertStatus(207);
        $errors = implode(' ', (array) $this->submit()->json('data.errors'));

        $this->assertStringContainsString('issue a new invoice', $errors);
        $this->assertStringNotContainsString('credit note to correct', $errors);
    }

    private function assertSameAnswer(TestResponse $first, TestResponse $replay): void
    {
        $this->assertSame($first->json('data.invoice_id'), $replay->json('data.invoice_id'));
        $this->assertSame(
            [$first->status(), $first->json('message'), $first->json('data.status'), $first->json('data.compliance_status')],
            [$replay->status(), $replay->json('message'), $replay->json('data.status'), $replay->json('data.compliance_status')],
        );
    }

    private function submit(): TestResponse
    {
        return $this->withHeaders([...$this->credential, 'Idempotency-Key' => 'key-1'])
            ->postJson('/api/v1/pipeline/submit', [
                'org_id' => $this->acme->id,
                'invoice_number' => 'INV-1',
                'type' => 'simplified',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'auto_submit' => true,
                'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ]);
    }
}

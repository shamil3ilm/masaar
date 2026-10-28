<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A licence grants what it lists, and only that.
 *
 * Each tier carried a feature list - Starter simplified invoices, Professional
 * adding standard invoices, credit and debit notes and webhooks - and nothing
 * read it on a request. A Starter licence issued B2B invoices and managed
 * webhooks exactly as an Enterprise one did.
 */
class LicenseFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_starter_cannot_issue_b2b(): void
    {
        $this->postJson('/api/v1/invoices', ['type' => 'standard'], $this->licence('starter'))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LICENSE_FEATURE_UNAVAILABLE')
            ->assertJsonPath('error.details.feature', 'standard_invoices');
    }

    public function test_starter_issues_simplified_invoices(): void
    {
        $status = $this->postJson('/api/v1/invoices', ['type' => 'simplified'], $this->licence('starter'))->status();

        $this->assertNotSame(403, $status, 'a Starter licence was refused the one document it includes');
    }

    public function test_a_credit_note_needs_its_feature(): void
    {
        $credential = $this->licence('professional', ['simplified_invoices', 'standard_invoices']);

        $this->postJson('/api/v1/invoices', ['type' => 'standard', 'document_type' => 'credit_note'], $credential)
            ->assertStatus(403)
            ->assertJsonPath('error.details.feature', 'credit_notes');
    }

    /**
     * The pipeline accepts an ERP's invoice_type for type; the gate reads it.
     */
    public function test_the_pipeline_alias_is_gated(): void
    {
        $this->postJson('/api/v1/pipeline/submit', ['invoice_type' => 'standard'], $this->licence('starter'))
            ->assertStatus(403)
            ->assertJsonPath('error.details.feature', 'standard_invoices');
    }

    /**
     * Filing an invoice that already exists is gated by what it is.
     *
     * The gate read the document in the request, and the compliance routes
     * take only an id: an invoice made elsewhere - in the portal - could be
     * issued and filed through a licence that does not include its kind.
     */
    public function test_filing_an_existing_invoice_is_gated(): void
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-B2B-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $credential = $this->licence('starter');

        foreach (['generate', 'submit'] as $step) {
            $this->postJson("/api/v1/compliance/{$step}/{$invoice->id}", [], $credential)
                ->assertStatus(403)
                ->assertJsonPath('error.details.feature', 'standard_invoices');
        }
    }

    public function test_webhooks_need_their_feature(): void
    {
        $this->getJson('/api/v1/webhooks', $this->licence('starter'))
            ->assertStatus(403)
            ->assertJsonPath('error.details.feature', 'webhook_notifications');

        $this->getJson('/api/v1/webhooks', $this->licence('professional', key: 'pro'))->assertOk();
    }

    /**
     * A licence issued without a list has its tier's - not nothing, which
     * would refuse every request from every licence issued before this.
     */
    public function test_no_list_means_the_tiers(): void
    {
        $status = $this->postJson('/api/v1/invoices', ['type' => 'standard'], $this->licence('professional'))->status();

        $this->assertNotSame(403, $status, 'a Professional licence with no stored list was refused standard invoices');
    }

    /**
     * @param  list<string>|null  $features
     * @return array<string, string>
     */
    private function licence(string $tier, ?array $features = null, string $key = 'acme'): array
    {
        License::create([
            'org_id' => $this->acme->id,
            'api_key' => 'cp_test_'.$key,
            'api_secret_hash' => License::hashSecret('secret-'.$key),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@example.test',
            'environment' => 'sandbox',
            'tier' => $tier,
            'status' => 'active',
            'features' => $features,
            'scopes' => ['invoice.submit', 'invoice.read', 'webhook.manage', 'compliance.status'],
        ]);

        return ['X-API-Key' => 'cp_test_'.$key, 'X-API-Secret' => 'secret-'.$key, 'Accept' => 'application/json'];
    }
}

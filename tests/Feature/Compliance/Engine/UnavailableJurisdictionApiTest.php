<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Engine;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An ERP filing for a country this deployment has switched off is told so,
 * in the API's error envelope, before anything is drafted.
 */
class UnavailableJurisdictionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_switched_off_country_answers_422(): void
    {
        config(['peppol.countries.AE.enabled' => false]);

        $organization = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);
        $issued = License::createWithCredentials([
            'org_id' => $organization->id,
            'organization_name' => 'Gulf Trading',
            'contact_email' => 'erp@gulf.test',
            'tier' => 'starter',
        ]);

        $this->withHeaders(['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']])
            ->postJson('/api/v1/pipeline/submit', [
                'org_id' => $organization->id,
                'type' => 'simplified',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'currency' => 'AED',
                // The pipeline still validates as Saudi (exchange rate to SAR);
                // per-country request rules arrive with the engine's rules port.
                'exchange_rate' => 1.02,
                'buyer_name' => 'Buyer',
                'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 5]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'JURISDICTION_UNAVAILABLE');

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }
}

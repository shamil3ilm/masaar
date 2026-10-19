<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A partner reading its own usage.
 *
 * The month went from the query string straight into a string parameter
 * under strict types: month[]=x raised a TypeError and the partner got a 500,
 * and any other string was looked up as though it were a month.
 */
class LicenseUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_malformed_month_is_refused(): void
    {
        $credential = $this->licence();

        $this->getJson('/api/license/usage?month[]=2026-01', $credential)->assertUnprocessable();
        $this->getJson('/api/license/usage?month=last-year', $credential)->assertUnprocessable();
    }

    public function test_a_month_reads_its_usage(): void
    {
        $this->getJson('/api/license/usage?month=2026-01', $this->licence())->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function licence(): array
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        License::create([
            'org_id' => $acme->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret('secret-acme'),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@example.test',
            'environment' => 'sandbox',
            'tier' => 'professional',
            'status' => 'active',
            'scopes' => ['invoice.submit', 'invoice.read'],
        ]);

        return ['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => 'secret-acme', 'Accept' => 'application/json'];
    }
}

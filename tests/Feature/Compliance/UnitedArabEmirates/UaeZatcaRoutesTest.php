<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The licence-key API's step-by-step ZATCA routes refuse a UAE organization
 * with 422 and name the routes its invoices take, rather than answering
 * with ZATCA's behaviour. A Saudi organization is let through as before.
 */
class UaeZatcaRoutesTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_uae_organization_is_sent_elsewhere(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $credential = $this->licence($this->uaeOrganization());
        $invoiceId = (string) Str::uuid();

        foreach ([['POST', "/api/v1/compliance/generate/{$invoiceId}"], ['POST', "/api/v1/compliance/validate/{$invoiceId}"],
            ['POST', "/api/v1/compliance/submit/{$invoiceId}"], ['GET', "/api/v1/compliance/status/{$invoiceId}"]] as [$method, $path]) {
            $this->send($credential, $method, $path)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'JURISDICTION_ENDPOINT_MISMATCH')
                ->assertJsonPath('error.message', fn (string $message): bool => str_contains($message, 'POST /api/v1/pipeline/submit')
                    && str_contains($message, 'United Arab Emirates'));
        }
    }

    public function test_saudi_organization_passes_through(): void
    {
        $saudi = Organization::create(['name' => 'Acme', 'name_ar' => 'أكمي', 'country' => 'SA']);

        $this->send($this->licence($saudi), 'GET', '/api/v1/compliance/status/'.Str::uuid())
            ->assertNotFound();
    }

    /**
     * @return array<string, string>
     */
    private function licence(Organization $organization): array
    {
        $issued = License::createWithCredentials(['org_id' => $organization->id, 'organization_name' => 'Test', 'contact_email' => 'ops@example.test', 'tier' => 'enterprise']);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode(['invoice.submit', 'compliance.status'])]);

        return ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }

    /**
     * @param  array<string, string>  $credential
     */
    private function send(array $credential, string $method, string $path): TestResponse
    {
        return $this->withHeaders($credential)->json($method, $path);
    }
}

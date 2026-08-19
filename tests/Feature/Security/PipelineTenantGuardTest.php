<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Pipeline\Http\Controllers\PipelineController;
use App\Domains\Pipeline\Http\Requests\PipelineSubmitRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pipeline endpoint takes the organization in the request body, so the
 * credential has to decide whether that organization is the caller's.
 *
 * The guard used to skip itself when no tenant was resolved:
 *
 *     if ($authenticatedOrgId !== null && $authenticatedOrgId !== $organizationId)
 *
 * which made the one case it could not judge the one case it allowed. It now
 * refuses instead, and reads the tenant from TenantResolver rather than from a
 * request attribute set alongside it — the same fact in two places is how the
 * two come to disagree.
 */
class PipelineTenantGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
    }

    /**
     * A licence the request can actually authenticate with.
     *
     * Without one the licence middleware answers 401 and the request never
     * reaches the controller — so a test asserting "401 or 403" would pass
     * while proving nothing about the guard under test.
     *
     * Scopes are listed explicitly because License::hasScope() matches them
     * exactly — unlike ApiKey::hasScope(), it does not treat '*' as a
     * wildcard, so a licence granted '*' is refused by every scope check.
     *
     * @return array<string, string> Headers carrying the credential
     */
    private function credentialFor(Organization $organization): array
    {
        $secret = 'test-secret-'.$organization->id;

        License::create([
            'org_id' => $organization->id,
            'api_key' => 'cp_test_'.$organization->id,
            'api_secret_hash' => License::hashSecret($secret),
            'organization_name' => $organization->name,
            'contact_email' => 'ops@masaar.test',
            'environment' => 'sandbox',
            'tier' => 'starter',
            'status' => 'active',
            'scopes' => ['invoice.submit', 'compliance.status'],
        ]);

        return [
            'X-API-Key' => 'cp_test_'.$organization->id,
            'X-API-Secret' => $secret,
        ];
    }

    /**
     * A body that passes PipelineSubmitRequest.
     *
     * It has to: the form request runs before the controller, so an invalid
     * body is answered 422 and the guard never executes. document_type is the
     * DocumentType enum name rather than the UBL code 388, and type=standard
     * is B2B, which makes the buyer VAT number and address mandatory.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function payload(string $organizationId, array $overrides = []): array
    {
        return array_merge([
            'org_id' => $organizationId,
            'invoice_number' => 'INV-1',
            'type' => 'standard',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'buyer_address' => [
                'street' => 'King Fahd Road',
                'city' => 'Riyadh',
                'building_number' => '1234',
                'postal_code' => '12345',
                'country_code' => 'SA',
            ],
            'lines' => [[
                'description' => 'Item',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ], $overrides);
    }

    /**
     * Acme's credential, Rival's organization in the body.
     *
     * 403 specifically, not "401 or 403": 401 would mean the licence
     * middleware turned it away and the guard never ran, which is what an
     * unauthenticated version of this test was quietly asserting.
     */
    public function test_other_tenant_org_id_refused(): void
    {
        $response = $this->withHeaders($this->credentialFor($this->acme))
            ->postJson('/api/v1/pipeline/submit', $this->payload($this->rival->id));

        $response->assertForbidden();
    }

    /**
     * The same credential naming its own organization gets past the guard.
     *
     * Without this the test above proves only that something refused the
     * request, not that the guard is the thing distinguishing them.
     */
    public function test_own_org_id_passes_the_guard(): void
    {
        $response = $this->withHeaders($this->credentialFor($this->acme))
            ->postJson('/api/v1/pipeline/submit', $this->payload($this->acme->id));

        // What happens after the guard depends on onboarding and ZATCA
        // connectivity, neither of which this test sets up. Only the guard is
        // under test, so the assertion is that it did not refuse.
        //
        // 422 is checked as well: the form request runs ahead of the
        // controller, so a body that stopped validating would make both this
        // test and the cross-tenant one pass without the guard ever running.
        $this->assertNotSame(422, $response->status(), 'The payload no longer validates, so the guard never ran: '.$response->getContent());
        $this->assertNotSame(401, $response->status(), 'The credential did not authenticate.');
        $this->assertNotSame(403, $response->status(), 'The guard refused the credential its own organization.');
    }

    /**
     * Without a credential there is no tenant to compare against, and the
     * request must not reach the pipeline at all.
     */
    public function test_missing_credential_refused(): void
    {
        $this->postJson('/api/v1/pipeline/submit', $this->payload($this->acme->id))
            ->assertUnauthorized();
    }

    /**
     * The branch that was the vulnerability, exercised directly.
     *
     * Over HTTP the licence middleware answers 401 before an unresolved tenant
     * can reach the controller, so routing a request here proves nothing about
     * the controller. The old guard read
     *
     *     if ($authenticatedOrgId !== null && $authenticatedOrgId !== $organizationId)
     *
     * so with no tenant it fell through to the pipeline with whatever org_id
     * the body asked for. Calling submit() with an empty resolver is the only
     * way to show it now refuses.
     */
    public function test_unresolved_tenant_refused(): void
    {
        $request = PipelineSubmitRequest::create(
            '/api/v1/pipeline/submit',
            'POST',
            $this->payload($this->acme->id)
        );

        $request->setContainer($this->app)->validateResolved();

        $response = $this->app->make(PipelineController::class)->submit($request);

        $this->assertSame(401, $response->status());
    }

    public function test_controller_resolves_from_the_tenant_resolver(): void
    {
        // Constructor injection is the mechanism: if the controller went back
        // to reading a request attribute this dependency would be unused.
        $reflection = new \ReflectionClass(PipelineController::class);
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];

        $types = array_map(
            static fn (\ReflectionParameter $p) => $p->getType()?->getName(),
            $parameters
        );

        $this->assertContains(TenantResolver::class, $types);
    }
}

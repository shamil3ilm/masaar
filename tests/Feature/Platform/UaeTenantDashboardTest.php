<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A UAE organization's API dashboard counts its e-invoices, as the portal's
 * does, and its portal names UAE e-invoicing. TenantDashboardTest pins the
 * Saudi answer, and JurisdictionIsolationTest the Saudi tagline.
 */
class UaeTenantDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** Delivery and the report to the authority counted apart: the reported one is delivered too. */
    private const COUNTS = ['awaiting' => 2, 'delivered' => 2, 'rejected' => 1, 'failed' => 1, 'received' => 1, 'reported' => 1, 'report_failed' => 0, 'not_exchanged' => 0, 'not_transmitted' => 0];

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        $this->gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE', 'status' => 'active']);
        $rival = Organization::create(['name' => 'Rival LLC', 'country' => 'AE', 'status' => 'active']);

        foreach (['ready', 'sent', 'delivered', 'reported', 'rejected', 'failed'] as $status) {
            $this->document($this->gulf, PeppolDocument::DIRECTION_OUT, $status);
        }
        $this->document($this->gulf, PeppolDocument::DIRECTION_IN, 'received');
        $this->document($rival, PeppolDocument::DIRECTION_OUT, 'failed');
    }

    public function test_overview_counts_einvoices(): void
    {
        $response = $this->api()->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.e_invoices', self::COUNTS)
            ->assertJsonPath('data.invoices.total', 0);

        $this->assertSame(['invoices', 'e_invoices', 'generated_at'], array_keys($response->json('data')));
    }

    public function test_health_reads_einvoices_not_zatca(): void
    {
        $response = $this->api()->getJson('/api/dashboard/health')
            ->assertOk()
            ->assertJsonPath('data.e_invoices', self::COUNTS)
            ->assertJsonPath('data.status', 'warning');

        $this->assertSame(['e_invoices', 'checked_at', 'status'], array_keys($response->json('data')));
    }

    public function test_submissions_are_its_einvoices(): void
    {
        $this->api()->getJson('/api/dashboard/submissions')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => self::COUNTS]);
    }

    public function test_portal_tagline_names_the_country(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->member($this->gulf))
            ->get('/portal')
            ->assertOk()
            ->assertSee('UAE E-invoicing Portal')
            ->assertDontSee('ZATCA Portal'));
    }

    private function api(): static
    {
        $user = $this->member($this->gulf);

        return $this->withToken(JWTAuth::claims(['org_id' => $this->gulf->id, 'role' => 'member'])->fromUser($user));
    }

    private function member(Organization $organization): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);

        return $user;
    }

    private function document(Organization $organization, string $direction, string $status): void
    {
        PeppolDocument::acrossTenants()->create([
            'org_id' => $organization->id,
            'direction' => $direction,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'xml' => '<Invoice/>',
            // 'reported' is a report to the authority, on a delivered document.
            'status' => $status === 'reported' ? 'delivered' : $status,
            'reporting_status' => $status === 'reported' ? 'reported' : null,
        ]);
    }
}

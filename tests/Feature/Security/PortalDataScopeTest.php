<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the portal shows one tenant's figures and no one else's.
 *
 * CustomerPortalAccessTest covers who may reach a page. It would still pass if
 * every page rendered zeros, or if the counts silently included another
 * taxpayer's invoices, because it never looks at the numbers.
 *
 * That matters most right after the portal moved from hand-written
 * where('org_id', ...) filters onto BelongsToTenant's global scope: both
 * failure modes of that change - scoping to nothing, or scoping to everything -
 * are invisible to an access-control test.
 */
class PortalDataScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, [
            'role' => 'member',
            'status' => 'active',
        ]);
    }

    public function test_dashboard_counts_own_invoices(): void
    {
        $this->invoice($this->acme, 'ACME-1');
        $this->invoice($this->acme, 'ACME-2');
        $this->invoice($this->rival, 'RIVAL-1');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal')
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => $stats['invoices_today'] === 2
                && $stats['invoices_month'] === 2));
    }

    /**
     * The count landing on 0 would mean the scope resolved a null tenant, which
     * is the quieter way this conversion could have gone wrong.
     */
    public function test_dashboard_is_not_empty(): void
    {
        $this->invoice($this->acme, 'ACME-1');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal')
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => $stats['invoices_today'] > 0));
    }

    public function test_organization_shown_is_the_members_own(): void
    {
        $this->actingAs($this->member)
            ->get('/portal')
            ->assertOk()
            ->assertViewHas('organization', fn ($org) => $org->id === $this->acme->id);
    }

    public function test_submissions_page_excludes_other_tenants(): void
    {
        $this->invoice($this->acme, 'ACME-1');
        $this->invoice($this->rival, 'RIVAL-1');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/submissions')
            ->assertOk()
            ->assertDontSee('RIVAL-1'));
    }

    /**
     * The filter dropdown lists people, and membership is what decides who is
     * visible - users are not tenant-scoped, so the scope cannot do it here.
     */
    public function test_user_filter_lists_only_members(): void
    {
        $outsider = User::factory()->create(['name' => 'Outsider Person']);
        $outsider->organizations()->attach($this->rival->id, [
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($this->member)
            ->get('/portal/submissions')
            ->assertOk()
            ->assertViewHas('users', function ($users) use ($outsider) {
                return ! $users->contains('id', $outsider->id)
                    && $users->contains('id', $this->member->id);
            });
    }

    public function test_certificates_page_loads(): void
    {
        $this->actingAs($this->member)
            ->get('/portal/certificates')
            ->assertOk()
            ->assertViewHas('organization');
    }

    private function invoice(Organization $organization, string $number): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }
}

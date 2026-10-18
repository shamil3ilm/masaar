<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A platform admin suspends an organization with a reason: it issues
 * nothing new, and keeps its data, its exports and the filing of what it
 * already issued.
 */
class OrganizationSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $operations;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'status' => Organization::STATUS_ACTIVE]);
        $this->operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_suspending_takes_a_reason(): void
    {
        $this->actingAs($this->operations)->post(route('admin.organizations.suspend', $this->acme->id), [])->assertSessionHasErrors('reason');

        $this->actingAs($this->operations)->post(route('admin.organizations.suspend', $this->acme->id), ['reason' => 'Unpaid since March'])
            ->assertSessionHas('success');

        $acme = $this->acme->fresh();
        $this->assertSame([Organization::STATUS_SUSPENDED, 'Unpaid since March', $this->operations->id], [$acme->status, $acme->suspension_reason, $acme->suspended_by]);
        $this->actingAs($this->operations)->get(route('admin.organization.detail', $this->acme->id))->assertOk()->assertSee('Unpaid since March');
    }

    public function test_suspended_it_still_reads_and_files(): void
    {
        $this->actingAs($this->operations)->post(route('admin.organizations.suspend', $this->acme->id), ['reason' => 'Unpaid']);
        $acme = $this->acme->fresh();
        $access = app(Access::class);

        $this->assertSame('status', $access->decide($acme, $this->member, Feature::IssueInvoices)->code);
        $this->assertTrue($access->decide($acme, $this->member, Feature::FileWithAuthority)->allowed, 'what was issued is still filed');
        app(FilingClearance::class)->assertMayFile($acme);

        $portal = $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
        $portal->get(route('portal.invoices.index'))->assertOk()->assertSee(__('This company is suspended'));
        $portal->get(route('portal.reports.invoices'))->assertOk();

        $this->actingAs($this->operations)->post(route('admin.organizations.reactivate', $this->acme->id))->assertSessionHas('success');
        $this->assertTrue($access->decide($this->acme->fresh(), $this->member, Feature::IssueInvoices)->allowed);
    }

    public function test_support_cannot_suspend(): void
    {
        $support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);

        $this->actingAs($support)->post(route('admin.organizations.suspend', $this->acme->id), ['reason' => 'x'])->assertForbidden();
        $this->assertSame(Organization::STATUS_ACTIVE, $this->acme->fresh()->status);
    }
}

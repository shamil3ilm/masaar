<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Auth\Models\AdminRole;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\AdminRoles;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Admin roles as data: the four system roles seeded from code, and custom
 * roles made from the permission set, held to the same rules - nobody acts
 * on themselves, an owner always remains, rank still decides.
 */
class CustomAdminRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);
    }

    public function test_the_four_roles_are_data(): void
    {
        $roles = app(AdminRoles::class)->all();

        $this->assertSame(['owner', 'operations', 'support', 'auditor'], array_keys($roles));
        $this->assertTrue($roles['support']['system']);
        $this->assertSame(PlatformRole::Support->permissions(), $roles['support']['permissions']);
        $this->assertSame(AdminPermission::cases(), $roles['owner']['permissions']);
    }

    public function test_a_custom_role_grants_its_permissions(): void
    {
        $this->actingAs($this->owner)->post(route('admin.roles.store'), [
            'name' => 'Billing clerk',
            'rank' => '2',
            'permissions' => ['console.view', 'billing.manage'],
        ])->assertSessionHas('success');

        $clerk = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'billing_clerk']);

        $this->assertTrue($clerk->canAdmin('billing.manage'));
        $this->assertFalse($clerk->canAdmin(AdminPermission::LicencesManage));
        $this->assertNull($clerk->platformRole(), 'none of the four');
        $this->actingAs($clerk)->get('/admin')->assertOk();
        $this->actingAs($clerk)->get(route('admin.roles.index'))->assertForbidden();
    }

    /**
     * Rank still decides who acts on whose access: a custom role of rank 2
     * cannot suspend support, also 2, but can a member of the public.
     */
    public function test_rank_still_applies_to_custom_roles(): void
    {
        app(AdminRoles::class)->save(['key' => 'desk', 'name' => 'Desk', 'description' => null, 'permissions' => ['console.view', 'users.manage'], 'rank' => 2], null);
        $desk = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'desk']);
        $support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $someone = User::factory()->create();

        $this->actingAs($desk)->post("/admin/users/{$support->id}/suspend", ['reason' => 'test'])->assertSessionHas('error');
        $this->actingAs($desk)->post("/admin/users/{$someone->id}/suspend", ['reason' => 'test'])->assertSessionHas('success');

        $this->assertTrue($support->fresh()->isActive());
        $this->assertFalse($someone->fresh()->isActive());
    }

    public function test_an_admin_takes_a_custom_role(): void
    {
        app(AdminRoles::class)->save(['key' => 'desk', 'name' => 'Desk', 'description' => null, 'permissions' => ['console.view'], 'rank' => 1], null);
        $newcomer = User::factory()->create(['email' => 'desk@example.test']);

        $this->actingAs($this->owner)->post('/admin/admins', ['email' => 'desk@example.test', 'role' => 'desk'])->assertSessionHas('success');
        $this->assertSame('desk', $newcomer->fresh()->platform_role);

        $this->actingAs($this->owner)->post('/admin/admins', ['email' => 'desk@example.test', 'role' => 'nobody'])->assertSessionHasErrors('role');
    }

    public function test_system_and_held_roles_stay(): void
    {
        app(AdminRoles::class)->save(['key' => 'desk', 'name' => 'Desk', 'description' => null, 'permissions' => ['console.view'], 'rank' => 1], null);
        User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'desk']);

        $this->actingAs($this->owner)->post(route('admin.roles.destroy', 'desk'))->assertSessionHas('error');
        $this->actingAs($this->owner)->post(route('admin.roles.update', 'support'), ['name' => 'Support', 'rank' => '3', 'permissions' => ['admins.manage']])->assertSessionHas('error');

        $this->assertTrue(app(AdminRoles::class)->exists('desk'));

        // Removed with who removed it, and a role of the same name can be made again.
        app(AdminRoles::class)->save(['key' => 'spare', 'name' => 'Spare', 'description' => null, 'permissions' => ['console.view'], 'rank' => 1], null);
        $this->actingAs($this->owner)->post(route('admin.roles.destroy', 'spare'))->assertSessionHas('success');
        $this->assertFalse(app(AdminRoles::class)->exists('spare'));
        $this->assertSame($this->owner->id, AdminRole::withTrashed()->where('key', 'spare')->value('deleted_by'));
        app(AdminRoles::class)->save(['key' => 'spare', 'name' => 'Spare', 'description' => null, 'permissions' => ['console.view'], 'rank' => 1], null);
        $this->assertTrue(app(AdminRoles::class)->exists('spare'));
        $this->assertFalse(User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support'])->canAdmin('admins.manage'));
    }

    /**
     * A platform admin switches issuing off for one person from the
     * organization's page, and the API's issuing routes hold it too.
     */
    public function test_a_person_switch_holds_everywhere(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $member = User::factory()->create();
        $member->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($this->owner)->post(route('admin.organizations.features', $organization->id), [
            'feature' => Feature::IssueInvoices->value, 'state' => 'off', 'reason' => 'On leave', 'subject' => 'user:'.$member->id,
        ])->assertSessionHas('success');

        $this->assertSame('off', app(FeatureOverrides::class)->forPeople($organization->id)['user:'.$member->id]['issue_invoices']['state']);

        $token = JWTAuth::claims(['org_id' => $organization->id, 'role' => 'member'])->fromUser($member);
        $this->withToken($token)->postJson('/api/compliance/sa/submit/'.Str::uuid())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_014');
    }
}

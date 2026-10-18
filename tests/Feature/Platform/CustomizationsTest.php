<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * One store and one resolver for every customization: the shipped default,
 * then the platform, the organization, the branch and the person, the nearest
 * winning unless a level above locked its own - and each answer says where
 * it came from.
 */
class CustomizationsTest extends TestCase
{
    use RefreshDatabase;

    private Customizations $customizations;

    private Organization $acme;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customizations = app(Customizations::class);
        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_the_nearest_level_wins_with_source(): void
    {
        $member = Scope::member($this->acme->id, $this->member->id);

        $shipped = $this->customizations->resolve('printing.paper', $member);
        $this->assertSame(['a4', null, Level::User], [$shipped->value, $shipped->source, $shipped->at]);
        $this->assertTrue($shipped->inherited());

        $this->customizations->set('printing.paper', Level::Platform, Scope::platform(), 'letter', $this->admin->id);
        $this->customizations->set('printing.paper', Level::Organization, Scope::organization($this->acme->id), 'a5', $this->admin->id);
        $this->assertSame([Level::Organization, 'a5'], [$this->customizations->resolve('printing.paper', $member)->source, $this->customizations->get('printing.paper', $member)]);

        $this->customizations->set('printing.paper', Level::User, $member, '58mm', $this->member->id);
        $mine = $this->customizations->resolve('printing.paper', $member);

        $this->assertSame(['58mm', Level::User, '58mm'], [$mine->value, $mine->source, $mine->own]);
        $this->assertFalse($mine->inherited());
        $this->assertSame(['platform' => 'letter', 'organization' => 'a5', 'user' => '58mm'], $mine->levels);
    }

    public function test_reset_returns_to_inherited(): void
    {
        $organization = Scope::organization($this->acme->id);
        $this->customizations->set('printing.paper', Level::Organization, $organization, 'a5', $this->admin->id);
        $this->assertSame('a5', $this->customizations->get('printing.paper', $organization));

        $this->customizations->reset('printing.paper', Level::Organization, $organization, $this->admin->id);

        $this->assertSame('a4', $this->customizations->get('printing.paper', $organization));
        $this->assertNull($this->customizations->own('printing.paper', Level::Organization, $organization));
        $this->assertSame(0, DB::table('customizations')->whereNull('deleted_at')->count());
        $this->assertSame($this->admin->id, DB::table('customizations')->value('deleted_by'), 'who reset it is kept');

        $this->customizations->set('printing.paper', Level::Organization, $organization, 'letter', $this->admin->id);
        $this->assertSame('letter', $this->customizations->get('printing.paper', $organization), 'the reset row comes back');
        $this->assertSame(1, DB::table('customizations')->count());
    }

    /**
     * Cached per holder, and forgotten on change: the next read sees it.
     */
    public function test_a_change_is_read_back(): void
    {
        $member = Scope::member($this->acme->id, $this->member->id);
        $this->assertSame('a4', $this->customizations->get('printing.paper', $member));

        DB::transaction(function () use ($member): void {
            $this->customizations->set('printing.paper', Level::User, $member, '80mm', $this->member->id);
        });
        $this->assertSame('80mm', $this->customizations->get('printing.paper', $member));

        $this->customizations->set('printing.paper', Level::User, $member, 'a5', $this->member->id);
        $this->assertSame('a5', $this->customizations->get('printing.paper', $member));
    }

    public function test_a_platform_lock_holds_below(): void
    {
        $this->customizations->set('security.require_two_factor', Level::Organization, Scope::organization($this->acme->id), false, $this->admin->id);
        $this->customizations->set('security.require_two_factor', Level::Platform, Scope::platform(), true, $this->admin->id, locked: true);

        $resolved = $this->customizations->resolve('security.require_two_factor', Scope::organization($this->acme->id));
        $this->assertSame([true, Level::Platform, Level::Platform], [$resolved->value, $resolved->source, $resolved->lockedBy]);
        $this->assertTrue($this->acme->fresh()->require_two_factor);

        $this->expectException(DomainException::class);
        $this->customizations->set('security.require_two_factor', Level::Organization, Scope::organization($this->acme->id), false, $this->admin->id);
    }

    /**
     * The organization's own prices setting stays in its column; the
     * attribute reads it, or the platform's when it has none.
     */
    public function test_a_column_level_inherits_when_null(): void
    {
        $this->assertNull(DB::table('organizations')->where('id', $this->acme->id)->value('prices_include_vat'));
        $this->assertFalse($this->acme->fresh()->prices_include_vat);

        $this->customizations->set('invoices.prices_include_vat', Level::Platform, Scope::platform(), true, $this->admin->id);
        $this->assertTrue($this->acme->fresh()->prices_include_vat);

        $this->customizations->set('invoices.prices_include_vat', Level::Organization, Scope::organization($this->acme->id), false, $this->admin->id);
        $this->assertFalse($this->acme->fresh()->prices_include_vat);
        $this->assertSame(0, (int) DB::table('organizations')->where('id', $this->acme->id)->value('prices_include_vat'));
    }

    public function test_bad_values_and_levels_are_refused(): void
    {
        try {
            $this->customizations->set('printing.paper', Level::Organization, Scope::organization($this->acme->id), 'tabloid', $this->admin->id);
            $this->fail('A paper that is not one was stored.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('customizations')->count());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->customizations->set('security.require_two_factor', Level::User, Scope::member($this->acme->id, $this->member->id), true, $this->member->id);
    }

    public function test_every_change_is_audited(): void
    {
        $this->customizations->set('printing.paper', Level::Organization, Scope::organization($this->acme->id), 'a5', $this->admin->id);
        $this->customizations->set('security.require_two_factor', Level::Platform, Scope::platform(), true, $this->admin->id);

        $paper = DB::table('audit_logs')->where('action', 'customization.changed')->first();
        $this->assertSame(['value' => 'a5'], json_decode((string) $paper->new_values, true));
        $this->assertSame('printing.paper', json_decode((string) $paper->metadata, true)['key']);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'security.customization.changed')->where('user_id', $this->admin->id)->exists());
    }

    public function test_the_settings_page_shows_the_source(): void
    {
        $this->portal($this->admin)->get(route('portal.settings.edit'))
            ->assertOk()
            ->assertSee(__('Shipped default'))
            ->assertDontSee(__('Reset to inherited'));

        $this->portal($this->admin)->post(route('portal.settings.update', 'printing.paper'), ['value' => 'a5'])->assertSessionHas('success');

        $this->portal($this->admin)->get(route('portal.settings.edit'))->assertSee(__('Set here'))->assertSee(__('Reset to inherited'));

        $this->portal($this->admin)->post(route('portal.settings.update', 'printing.paper'), ['reset' => '1'])->assertSessionHas('success');
        $this->assertSame('a4', $this->customizations->get('printing.paper', Scope::organization($this->acme->id)));

        $this->portal($this->member)->get(route('portal.settings.edit'))->assertForbidden();
    }

    /**
     * A person's page sets only their own: naming the organization's
     * setting finds nothing.
     */
    public function test_a_member_sets_their_preferences(): void
    {
        $this->portal($this->member)->get(route('portal.preferences.edit'))->assertOk()->assertSee(__('Language'));

        $this->portal($this->member)->post(route('portal.preferences.update', 'printing.paper'), ['value' => '80mm'])->assertSessionHas('success');
        $this->portal($this->member)->post(route('portal.preferences.update', 'account.locale'), ['value' => 'ar'])->assertSessionHas('success');
        $this->portal($this->member)->post(route('portal.preferences.update', 'security.require_two_factor'), ['value' => '0'])->assertNotFound();

        $this->assertSame('80mm', $this->customizations->get('printing.paper', Scope::member($this->acme->id, $this->member->id)));
        $this->assertSame('ar', $this->member->fresh()->locale);
        $this->assertSame('a4', $this->customizations->get('printing.paper', Scope::member($this->acme->id, $this->admin->id)));
    }

    public function test_the_platform_default_is_the_consoles(): void
    {
        $owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);
        $auditor = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'auditor']);

        $this->actingAs($auditor)->get('/admin/settings')->assertOk()->assertSee(__('Defaults for everyone'));
        $this->actingAs($auditor)->post(route('admin.customizations.update', 'printing.paper'), ['value' => 'a5'])->assertForbidden();

        $this->actingAs($owner)->post(route('admin.customizations.update', 'printing.paper'), ['value' => 'a5'])->assertSessionHas('success');

        $this->assertSame('a5', $this->customizations->get('printing.paper', Scope::member($this->acme->id, $this->member->id)));
    }

    /**
     * Required on the platform and locked: every organization requires it,
     * and its members are sent to set it up.
     */
    public function test_a_platform_requirement_reaches_members(): void
    {
        $this->customizations->set('security.require_two_factor', Level::Platform, Scope::platform(), true, $this->admin->id, locked: true);

        $this->portal($this->member)->get('/portal')->assertRedirect(route('account.show').'#two-factor');
    }

    private function portal(User $user): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}

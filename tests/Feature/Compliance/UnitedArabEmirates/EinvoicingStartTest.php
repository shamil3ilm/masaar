<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStart;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\SetupChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A UAE organization's e-invoicing start date: the day from which it must
 * issue through its service provider (UAE Electronic Invoicing Guidelines
 * v1.1, 6.1, 8.3). None set: not yet in scope. Set by the organization's
 * administrators or a platform admin, and every change audited.
 */
class EinvoicingStartTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private const TOKEN = 'einvoicing-start-token';

    private Organization $gulf;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        $this->gulf = $this->uaeOrganization(['einvoicing_start' => null], appointed: false);
        $this->gulf->forceFill(['status' => 'active'])->save();
        $this->admin = $this->member($this->gulf, 'admin');
    }

    public function test_no_date_means_not_in_scope(): void
    {
        $start = app(EinvoicingStart::class);

        $this->assertNull($start->date($this->gulf));
        $this->assertFalse($start->inScope($this->gulf));
    }

    public function test_administrator_sets_it_with_audit(): void
    {
        $this->submit($this->admin, route('portal.company.einvoicing-start'), ['einvoicing_start' => '2027-01-01'])
            ->assertRedirect(route('portal.company.edit'))
            ->assertSessionHas('success');

        $start = app(EinvoicingStart::class);
        $this->assertSame('2027-01-01', $start->date($this->gulf)?->toDateString());
        $this->assertFalse($start->inScope($this->gulf, CarbonImmutable::parse('2026-12-31')));
        $this->assertTrue($start->inScope($this->gulf, CarbonImmutable::parse('2027-01-01')));

        $entry = AuditLog::query()->where('action', EinvoicingStart::AUDIT_ACTION)->sole();
        $this->assertSame([$this->admin->id, (string) $this->gulf->id, null, '2027-01-01'], [
            $entry->user_id, $entry->org_id, $entry->old_values['einvoicing_start'], $entry->new_values['einvoicing_start'],
        ]);
    }

    public function test_members_cannot_set_it(): void
    {
        $this->submit($this->member($this->gulf, 'member'), route('portal.company.einvoicing-start'), ['einvoicing_start' => '2027-01-01'])
            ->assertForbidden();

        $this->assertNull(app(EinvoicingStart::class)->date($this->gulf));
    }

    public function test_platform_admin_sets_and_clears_it(): void
    {
        $owner = User::factory()->create([
            'is_platform_admin' => true, 'platform_role' => 'owner',
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ]);
        $url = route('admin.organizations.einvoicing-start', $this->gulf->id);

        $this->submit($owner, $url, ['einvoicing_start' => '2027-07-01'])->assertRedirect()->assertSessionHas('success');
        $this->submit($owner, $url, ['einvoicing_start' => ''])->assertRedirect()->assertSessionHas('success');

        $this->assertNull(app(EinvoicingStart::class)->date($this->gulf));
        $entries = AuditLog::query()->where('action', EinvoicingStart::AUDIT_ACTION)->orderBy('id')->get();
        $this->assertSame([[null, '2027-07-01'], ['2027-07-01', null]], $entries->map(fn (AuditLog $entry): array => [
            $entry->old_values['einvoicing_start'], $entry->new_values['einvoicing_start'],
        ])->all());
        $this->assertSame([$owner->id, $owner->id], $entries->pluck('user_id')->all());

        $this->asRequest(fn (): TestResponse => $this->actingAs($owner)->get($url))
            ->assertOk()
            ->assertSee('E-invoicing start date');
    }

    /** The setup steps say the stage, on its dashboard and the console's organization page. */
    public function test_setup_step_shows_the_stage(): void
    {
        $step = fn (): array => collect(app(SetupChecklist::class)->steps($this->gulf->fresh()))->firstWhere('key', 'uae_einvoicing_stage');

        $this->assertSame(['Before e-invoicing', false, false], [$step()['title'], $step()['done'], $step()['required']]);

        app(EinvoicingStart::class)->set($this->gulf, '2026-07-01', null);
        $this->assertSame(['E-invoicing via provider from 2026-07-01', true], [$step()['title'], $step()['done']]);

        $owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);
        $this->asRequest(fn (): TestResponse => $this->actingAs($owner)->get("/admin/organizations/{$this->gulf->id}"))
            ->assertOk()
            ->assertSee('E-invoicing via provider from 2026-07-01')
            ->assertSee(route('admin.organizations.einvoicing-start', $this->gulf->id), false);
    }

    public function test_company_page_hints_published_phases(): void
    {
        $this->asRequest(fn (): TestResponse => $this->actingAs($this->admin)
            ->withSession(['portal_org_id' => $this->gulf->id])
            ->get(route('portal.company.edit')))
            ->assertOk()
            ->assertSee('data-einvoicing-start', false)
            ->assertSee('2027-01-01')
            ->assertSee('2027-07-01')
            ->assertSee('2027-10-01');
    }

    /** @param  array<string, mixed>  $data */
    private function submit(User $user, string $url, array $data): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['portal_org_id' => $this->gulf->id, '_token' => self::TOKEN])
            ->from(route('portal.company.edit'))
            ->post($url, [...$data, '_token' => self::TOKEN]));
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}

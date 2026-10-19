<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Engine;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A country's panels reach the shared layouts through its module
 * (Presentation): the console lists them under the country's name, the
 * portal shows an organization only its own country's links, and a
 * country's notice shows where that country is filed.
 */
class ModulePanelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_groups_panels_by_country(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);

        $page = (string) $this->actingAs($admin)->get(route('admin.organizations'))->assertOk()->getContent();

        $saudi = strpos($page, '>Saudi Arabia<');
        $emirates = strpos($page, '>United Arab Emirates<');

        $this->assertNotFalse($saudi, 'The console names no Saudi group.');
        $this->assertNotFalse($emirates, 'The console names no UAE group.');
        $this->assertGreaterThan($saudi, strpos($page, 'href="'.route('admin.alerts').'"'));
        $this->assertGreaterThan($saudi, strpos($page, 'href="'.route('admin.certificates').'"'));
        $this->assertGreaterThan($emirates, strpos($page, 'href="'.route('admin.peppol').'"'));
    }

    public function test_portal_links_are_the_countrys(): void
    {
        $this->portal('SA')->get(route('portal.customers.index'))->assertOk()
            ->assertSee('href="'.route('portal.submissions').'"', false)
            ->assertDontSee('href="'.route('portal.received.index').'"', false);

        $this->portal('AE')->get(route('portal.customers.index'))->assertOk()
            ->assertSee('href="'.route('portal.received.index').'"', false)
            ->assertDontSee('href="'.route('portal.submissions').'"', false)
            ->assertDontSee('href="'.route('portal.certificates').'"', false);
    }

    public function test_the_notice_is_the_countrys(): void
    {
        config(['fatoora.environment' => 'simulation']);

        $this->portal('SA')->get(route('portal.customers.index'))->assertOk()->assertSee('invoices here are not legally filed');
        $this->portal('AE')->get(route('portal.customers.index'))->assertOk()->assertDontSee('invoices here are not legally filed');
    }

    private function portal(string $country): static
    {
        $organization = Organization::create(['name' => 'Acme '.$country, 'country' => $country, 'status' => Organization::STATUS_ACTIVE]);
        $member = User::factory()->create();
        $member->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);

        return $this->actingAs($member)->withSession(['portal_org_id' => $organization->id]);
    }
}

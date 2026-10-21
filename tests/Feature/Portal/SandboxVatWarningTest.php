<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\EnvironmentSwitch;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ZATCA's sandbox files only for the one test VAT number its certificate is
 * issued for. A company with its own VAT number passed every compliance
 * check there, was given a production certificate, and then had every
 * invoice rejected - with its VAT number locked by the onboarding. It is
 * told before it onboards, and the platform admin sees it on the setup list.
 */
class SandboxVatWarningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'fatoora.environment' => 'sandbox']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'status' => 'active',
            'vat_number' => '300000000000003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
    }

    public function test_sandbox_warns_about_own_vat(): void
    {
        $this->asAdmin()->get('/portal/zatca')->assertOk()
            ->assertSee('data-sandbox-vat', false)
            ->assertSee(__('ZATCA\'s sandbox files invoices only for its own test VAT number.'));
    }

    public function test_sandbox_test_vat_has_no_warning(): void
    {
        $this->acme->forceFill(['vat_number' => FatooraEnvironment::SANDBOX_VAT_NUMBER])->save();

        $this->asAdmin()->get('/portal/zatca')->assertOk()->assertDontSee('data-sandbox-vat', false);
    }

    public function test_simulation_has_no_warning(): void
    {
        app(EnvironmentSwitch::class)->set($this->acme, FatooraEnvironment::SIMULATION, null);

        $this->asAdmin()->get('/portal/zatca')->assertOk()->assertDontSee('data-sandbox-vat', false);
    }

    public function test_setup_lists_the_sandbox_problem(): void
    {
        $steps = collect(app(SetupChecklist::class)->steps($this->acme->fresh()))->keyBy('key');

        $this->assertTrue($steps->has('zatca-sandbox-vat'));
        $this->assertFalse($steps['zatca-sandbox-vat']['done']);
        $this->assertSame('admin.organizations.zatca-environment', $steps['zatca-sandbox-vat']['admin_route']);

        app(EnvironmentSwitch::class)->set($this->acme, FatooraEnvironment::SIMULATION, null);

        $this->assertFalse(collect(app(SetupChecklist::class)->steps($this->acme->fresh()))->contains('key', 'zatca-sandbox-vat'));
    }

    private function asAdmin(): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * Getting set up, for a UAE organization: its seller details and endpoint,
 * never ZATCA. A Saudi organization's steps are in JurisdictionIsolationTest.
 */
class UaeSetupTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_uae_steps_skip_zatca(): void
    {
        $gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE']);

        $this->dashboard($gulf)
            ->assertSee('Complete your UAE seller details')
            ->assertSee('sent to the buyer as a UAE e-invoice')
            ->assertDontSee('Connect to ZATCA')
            ->assertDontSee('signed and filed with ZATCA');

        $this->assertSame(
            ['uae_company', 'approved', 'uae_service_provider', 'numbering', 'catalogue', 'uae_invoice'],
            array_column(app(SetupChecklist::class)->steps($gulf), 'key'),
        );
    }

    public function test_complete_seller_details_are_done(): void
    {
        $steps = app(SetupChecklist::class)->steps($this->uaeOrganization());

        $this->assertTrue($steps[0]['done']);
        $this->assertFalse($steps[5]['done']);

        $incomplete = $this->uaeOrganization(['endpoint_id' => null]);
        $this->assertFalse(app(SetupChecklist::class)->steps($incomplete)[0]['done']);
    }

    public function test_issued_uae_invoice_is_done(): void
    {
        $gulf = $this->uaeOrganization();
        $this->uaeInvoice($gulf);

        $this->assertTrue(app(SetupChecklist::class)->steps($gulf)[5]['done']);
    }

    private function dashboard(Organization $organization): TestResponse
    {
        $admin = User::factory()->create();
        $admin->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        return $this->asRequest(fn () => $this->actingAs($admin)->get('/portal')->assertOk());
    }
}

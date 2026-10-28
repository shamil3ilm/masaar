<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A new company is shown what is left before its first invoice.
 */
class SetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    /**
     * A new company sees the next steps, not a red "contact support".
     */
    public function test_a_new_company_sees_the_steps(): void
    {
        $this->dashboard()
            ->assertOk()
            ->assertSee('Getting set up')
            ->assertSee('Complete your company details')
            ->assertSee(route('portal.zatca.show'), false)
            ->assertDontSee('No active certificate found');
    }

    public function test_a_member_gets_no_admin_links(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->get('/portal')
            ->assertOk()
            ->assertSee('Connect to ZATCA')
            ->assertDontSee(route('portal.zatca.show'), false);
    }

    /** A role that only reads cannot act on the steps, so is not shown them. */
    public function test_an_accountant_sees_no_steps(): void
    {
        $this->acme->update(['status' => 'pending_review']);
        $accountant = User::factory()->create();
        $accountant->organizations()->attach($this->acme->id, ['role' => 'accountant', 'status' => 'active']);

        $this->actingAs($accountant)->withSession(['portal_org_id' => $this->acme->id])
            ->get('/portal')
            ->assertOk()
            ->assertDontSee('Getting set up')
            ->assertDontSee('You can complete its details')
            ->assertSee('Your company is being reviewed.');
    }

    public function test_steps_follow_the_company(): void
    {
        $this->complete();

        $steps = collect(app(SetupChecklist::class)->steps($this->acme->fresh()))->pluck('done', 'key');

        $this->assertTrue($steps['company']);
        $this->assertTrue($steps['approved']);
        $this->assertTrue($steps['zatca']);
        $this->assertFalse($steps['invoice']);
    }

    /**
     * Once the required steps are done the list goes, optional ones or not.
     */
    public function test_it_goes_once_set_up(): void
    {
        $this->complete();
        Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-1',
            'type' => 'simplified',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ])->forceFill(['status' => 'issued', 'icv' => 1, 'hash' => base64_encode('h')])->save();

        $this->dashboard()->assertOk()->assertDontSee('Getting set up');
    }

    private function complete(): void
    {
        $this->acme->forceFill([
            'vat_number' => '300000000000003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'status' => 'active',
            'compliance_profile' => ['zatca_onboarded' => true],
        ])->save();

        License::createWithCredentials([
            'org_id' => $this->acme->id,
            'organization_name' => 'Acme Trading',
            'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Starter,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addYear(),
        ]);
    }

    private function dashboard(): TestResponse
    {
        return $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])->get('/portal');
    }
}

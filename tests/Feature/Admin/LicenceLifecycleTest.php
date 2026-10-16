<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suspending, reactivating and revoking a licence from the console.
 *
 * These three calls decide whether a partner's integration answers or stops
 * answering, and until now they existed only as service methods reachable from
 * a REPL. The screen is the subject here, and so is its failure behaviour: the
 * exception renderer in bootstrap/app.php only formats throwables for api/* and
 * v1/*, so anything a lifecycle call raises on a web route becomes an error
 * page unless the controller catches it.
 */
class LicenceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_licence_can_be_suspended(): void
    {
        $licence = $this->licence('Northwind');

        $response = $this->actingAs($this->admin())
            ->post("/admin/licences/{$licence->id}/suspend", ['reason' => 'Non-payment']);

        $response->assertRedirect(route('admin.licences.show', $licence->id));
        $response->assertSessionHas('success');

        $fresh = $licence->fresh();

        $this->assertSame(LicenseStatus::Suspended, $fresh->status);
        $this->assertSame('Non-payment', $fresh->suspension_reason);
        $this->assertNotNull($fresh->suspended_at);
    }

    /**
     * Cutting a partner off is a decision someone has to justify.
     *
     * The reason lands in suspension_reason and is the only record of why the
     * integration stopped working, so an empty one must not be accepted — and
     * the licence must still be serving traffic afterwards.
     */
    public function test_suspending_needs_a_reason(): void
    {
        $licence = $this->licence('Rawafid');

        $response = $this->actingAs($this->admin())
            ->from(route('admin.licences.show', $licence->id))
            ->post("/admin/licences/{$licence->id}/suspend", ['reason' => '']);

        $response->assertRedirect(route('admin.licences.show', $licence->id));
        $response->assertSessionHasErrors('reason');

        $fresh = $licence->fresh();

        $this->assertSame(LicenseStatus::Active, $fresh->status);
        $this->assertNull($fresh->suspension_reason);
    }

    /**
     * A throwable from a lifecycle call arrives as a sentence, not a stack trace.
     *
     * The premise was a double suspend, but LicenseManagementService guards no
     * transition at all: suspending a suspended licence, revoking a revoked one
     * and reactivating a revoked one each succeed silently. What is reachable
     * through these actions is a licence id that is not a licence — a stale
     * bookmark, or a row somebody removed — which findOrFail raises. Without the
     * catch the admin gets the framework's error page; with it, a redirect
     * carrying the reason.
     *
     * That redirect goes to the list, not to the detail page for the id that
     * does not resolve: see LicenceIssueTest for why.
     */
    public function test_a_failure_becomes_a_message(): void
    {
        $missing = '0193ffff-0000-7000-8000-0000000dead1';

        $response = $this->actingAs($this->admin())
            ->post("/admin/licences/{$missing}/suspend", ['reason' => 'Non-payment']);

        $response->assertRedirect(route('admin.licences.index'));
        $response->assertSessionHas('error');

        $this->assertStringContainsString($missing, (string) session('error'));
    }

    public function test_a_suspended_licence_comes_back(): void
    {
        $licence = $this->licence('Nakheel', LicenseStatus::Suspended);

        $response = $this->actingAs($this->admin())
            ->post("/admin/licences/{$licence->id}/reactivate");

        $response->assertRedirect(route('admin.licences.show', $licence->id));
        $response->assertSessionHas('success', 'Licence reactivated.');

        $this->assertSame(LicenseStatus::Active, $licence->fresh()->status);
    }

    public function test_a_licence_can_be_revoked(): void
    {
        $licence = $this->licence('Jazeera');

        $response = $this->actingAs($this->admin())
            ->post("/admin/licences/{$licence->id}/revoke", ['reason' => 'Contract ended']);

        $response->assertRedirect(route('admin.licences.show', $licence->id));
        $response->assertSessionHas('success', 'Licence revoked.');

        $this->assertSame(LicenseStatus::Revoked, $licence->fresh()->status);
    }

    /**
     * The detail page reads getLicenseDetails(), whose 'license' key is the
     * model as an array — so the view handles plain strings, not enums.
     */
    public function test_the_detail_page_shows_the_licence(): void
    {
        $licence = $this->licence('Mawarid');

        $response = $this->actingAs($this->admin())
            ->get("/admin/licences/{$licence->id}");

        $response->assertOk()
            ->assertSee('Mawarid')
            ->assertSee('ops@mawarid.test')
            ->assertSee($licence->api_key)
            ->assertSee('Professional')
            ->assertSee('Suspend licence');
    }

    /**
     * Reactivation is not offered on a licence that is already serving traffic.
     */
    public function test_actions_follow_the_current_status(): void
    {
        $licence = $this->licence('Dhabi');

        $response = $this->actingAs($this->admin())
            ->get("/admin/licences/{$licence->id}");

        $response->assertOk()
            ->assertDontSee(route('admin.licences.reactivate', $licence->id));
    }

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    private function licence(string $name, LicenseStatus $status = LicenseStatus::Active): License
    {
        $created = License::createWithCredentials([
            'organization_name' => $name,
            'contact_email' => 'ops@'.strtolower($name).'.test',
            'tier' => LicenseTier::Professional,
            'status' => $status,
            'expires_at' => now()->addYear(),
        ]);

        return $created['license'];
    }
}

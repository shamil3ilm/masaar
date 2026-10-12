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
 * The licence register has a screen.
 *
 * LicenseManagementService has listed, suspended, revoked, extended and
 * re-tiered licences since the beginning, reachable only over the partner API.
 * Issuing or even finding a partner's licence meant `license:generate` and a
 * psql prompt, so this asserts the list renders and that its status filter
 * actually narrows it rather than being decorative.
 */
class LicenceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_shows_licences(): void
    {
        $this->licence('Northwind');

        $this->actingAs($this->admin())
            ->get('/admin/licences')
            ->assertOk()
            ->assertSee('Northwind');
    }

    public function test_status_narrows_the_list(): void
    {
        $this->licence('Rawafid', LicenseStatus::Active);
        $this->licence('Nakheel', LicenseStatus::Revoked);

        $response = $this->actingAs($this->admin())->get('/admin/licences?status=active');

        $response->assertOk()
            ->assertSee('Rawafid')
            ->assertDontSee('Nakheel');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
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

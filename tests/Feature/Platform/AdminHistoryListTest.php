<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The console's lists - organizations, the audit log, licences and the
 * offline queue - sort, narrow and export with the same toolkit as the
 * portal's, and each CSV holds exactly what its list shows.
 */
class AdminHistoryListTest extends TestCase
{
    use PlatformRows;
    use RefreshDatabase;

    public function test_organizations_narrow_by_country(): void
    {
        $this->organization('Riyadh Trading');
        Organization::create(['name' => 'Dubai Trading', 'country' => 'AE']);

        $this->admin('/admin/organizations?country=AE')
            ->assertOk()
            ->assertSee('Dubai Trading')
            ->assertDontSee('Riyadh Trading');
    }

    public function test_organizations_sort_by_name(): void
    {
        $this->organization('Zahra Foods');
        $this->organization('Amal Stores');

        $this->admin('/admin/organizations?sort=name')
            ->assertOk()
            ->assertSeeInOrder(['Amal Stores', 'Zahra Foods']);
    }

    public function test_organizations_csv_is_the_filtered_set(): void
    {
        $this->organization('Riyadh Trading');
        Organization::create(['name' => 'Dubai Trading', 'country' => 'AE']);

        $csv = $this->admin('/admin/organizations/export.csv?country=AE')->assertOk()->streamedContent();

        $this->assertStringContainsString('Dubai Trading', $csv);
        $this->assertStringNotContainsString('Riyadh Trading', $csv);
    }

    public function test_audit_csv_follows_the_filters(): void
    {
        AuditLog::create(['action' => 'security.login.failed', 'entity_type' => 'User']);
        AuditLog::create(['action' => 'admin.licences.revoke', 'entity_type' => 'License']);

        $csv = $this->admin('/admin/audit/export.csv?category=security.')->assertOk()->streamedContent();

        $this->assertStringContainsString('security.login.failed', $csv);
        $this->assertStringNotContainsString('admin.licences.revoke', $csv);
    }

    public function test_audit_csv_needs_the_audit_permission(): void
    {
        $support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);

        $this->actingAs($support)->get('/admin/audit/export.csv')->assertForbidden();
    }

    public function test_licences_narrow_by_tier(): void
    {
        $this->licence('Starter Co', LicenseTier::Starter);
        $this->licence('Big Co', LicenseTier::Enterprise);

        $this->admin('/admin/licences?tier=enterprise')
            ->assertOk()
            ->assertSee('Big Co')
            ->assertDontSee('Starter Co');

        $csv = $this->admin('/admin/licences/export.csv?tier=enterprise')->assertOk()->streamedContent();

        $this->assertStringContainsString('Big Co', $csv);
        $this->assertStringNotContainsString('Starter Co', $csv);
    }

    public function test_queue_narrows_by_organization_name(): void
    {
        $this->queueItem($this->organization('Acme'), 'failed');
        $this->queueItem($this->organization('Globex'), 'failed');

        $this->admin('/admin/queue?organization=Glob')
            ->assertOk()
            ->assertSee('Globex')
            ->assertDontSee('Acme');

        $csv = $this->admin('/admin/queue/export.csv?organization=Glob')->assertOk()->streamedContent();

        $this->assertStringContainsString('Globex', $csv);
        $this->assertStringNotContainsString('Acme', $csv);
    }

    private function admin(string $uri): TestResponse
    {
        return $this->actingAs(User::factory()->create(['is_platform_admin' => true]))->get($uri);
    }

    private function licence(string $name, LicenseTier $tier): License
    {
        return License::createWithCredentials([
            'organization_name' => $name,
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@example.test',
            'tier' => $tier,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addYear(),
        ])['license'];
    }
}

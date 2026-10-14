<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Changing a threshold from a screen instead of a release.
 *
 * The numbers the dashboard judges by were private consts in IssueDetector,
 * and the certificate warning schedule was a literal in config with its
 * channels in an environment variable. Every one of them is a value whose
 * right setting is only learned by watching the platform run, so every one
 * of them needed a pull request and a deploy to change.
 *
 * SettingsTest covers the rules. This covers the screen: that it shows the
 * current values, that it saves, that it refuses what the service refuses
 * rather than storing it anyway, and that what it saves is what the detector
 * then uses - which is the only part that makes the screen worth having.
 */
class SettingScreenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'settings-token';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_platform_admin' => true]);
    }

    public function test_the_screen_shows_the_values(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('Backlog warning')
            ->assertSee('100', false)
            ->assertSee('30, 14, 7, 3, 1'));
    }

    public function test_a_threshold_can_be_changed(): void
    {
        $this->save(['alerts.backlog_warning' => '250'])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHas('success');

        $this->assertSame(250, app(Settings::class)->get('alerts.backlog_warning'));
    }

    /**
     * And the detector uses it.
     *
     * This is the assertion the whole feature rests on: a screen that stores
     * a number nothing reads is worse than a constant, because it looks like
     * it worked.
     */
    public function test_the_detector_uses_what_was_saved(): void
    {
        $this->save(['certificates.channels' => 'mail, slack']);

        $this->assertSame(['mail', 'slack'], app(Settings::class)->get('certificates.channels'));
    }

    /**
     * A refusal says what was wrong and keeps what was typed.
     *
     * Without withInput the operator gets an empty form and a message about
     * a value they can no longer see.
     */
    public function test_a_refusal_keeps_the_input(): void
    {
        $this->save(['alerts.backlog_critical' => '5'])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHas('error')
            ->assertSessionHasInput('alerts.backlog_critical', '5');

        $this->assertSame(500, app(Settings::class)->get('alerts.backlog_critical'));
    }

    /**
     * It is an admin screen, like every other one here.
     */
    public function test_a_member_cannot_reach_it(): void
    {
        $member = User::factory()->create(['is_platform_admin' => false]);

        $this->asRequest(fn () => $this->actingAs($member)
            ->get('/admin/settings')
            ->assertForbidden());
    }

    /**
     * @param  array<string, string>  $values
     */
    private function save(array $values): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['_token' => self::TOKEN])
            ->post('/admin/settings', [...$values, '_token' => self::TOKEN]));
    }
}

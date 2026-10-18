<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The operational numbers, and the rules that keep them meaningful.
 *
 * These decide when the dashboard calls something a problem. They were
 * private consts in IssueDetector, so the right value - which is only learned
 * by watching the platform run - could only be changed by editing PHP and
 * shipping a release.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(Settings::class);
    }

    /**
     * A setting nobody has saved still has a value.
     */
    public function test_an_unsaved_setting_has_a_default(): void
    {
        $this->assertSame(100, $this->settings->get('alerts.backlog_warning'));
        $this->assertSame([30, 14, 7, 3, 1], $this->settings->get('certificates.notify_at_days'));
    }

    public function test_a_saved_setting_is_returned(): void
    {
        $this->assertSame([], $this->settings->put(['alerts.backlog_warning' => '250']));

        $this->assertSame(250, $this->settings->get('alerts.backlog_warning'));
    }

    /**
     * And is still there for the next reader.
     *
     * The whole map is cached as one entry, so a write that forgets to
     * invalidate it leaves every reader on the old value until the cache
     * expires - which, being rememberForever, is never.
     */
    public function test_a_write_invalidates_the_cache(): void
    {
        $this->settings->get('alerts.backlog_warning');

        $this->settings->put(['alerts.backlog_warning' => '250']);

        $this->assertSame(250, app(Settings::class)->get('alerts.backlog_warning'));
    }

    /**
     * A critical threshold below its warning threshold inverts the severity
     * logic in silence: every backlog past the warning would read critical.
     */
    public function test_critical_must_exceed_warning(): void
    {
        $problems = $this->settings->put(['alerts.backlog_critical' => '50']);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('must be greater than', $problems[0]);
        $this->assertSame(500, $this->settings->get('alerts.backlog_critical'), 'it was stored anyway');
    }

    /**
     * Raising both in one submission is legitimate.
     *
     * Checking each against the stored value rather than against the rest of
     * the submission would refuse whichever half arrived first.
     */
    public function test_both_thresholds_move_together(): void
    {
        $problems = $this->settings->put([
            'alerts.backlog_warning' => '1000',
            'alerts.backlog_critical' => '2000',
        ]);

        $this->assertSame([], $problems);
        $this->assertSame(1000, $this->settings->get('alerts.backlog_warning'));
        $this->assertSame(2000, $this->settings->get('alerts.backlog_critical'));
    }

    /**
     * Nothing is stored unless all of it is valid.
     *
     * A form that saved its good fields and reported the bad one leaves the
     * operator looking at a screen that is half what they asked for.
     */
    public function test_a_bad_value_saves_nothing(): void
    {
        $problems = $this->settings->put([
            'alerts.rejections_warning' => '25',
            'alerts.backlog_warning' => 'lots',
        ]);

        $this->assertNotSame([], $problems);
        $this->assertSame(10, $this->settings->get('alerts.rejections_warning'));
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    public static function refusals(): array
    {
        return [
            'not a number' => ['alerts.backlog_warning', 'lots'],
            'below the floor' => ['alerts.backlog_warning', '0'],
            'above the ceiling' => ['alerts.backlog_warning', '100001'],
            'a fraction' => ['alerts.backlog_warning', '10.5'],
            'no days at all' => ['certificates.notify_at_days', ''],
            'a day of zero' => ['certificates.notify_at_days', '30, 0'],
            'an unknown channel' => ['certificates.channels', 'mail, carrier-pigeon'],
            'no channel at all' => ['certificates.channels', ''],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_bad_value_is_refused(string $key, mixed $value): void
    {
        $this->assertNotSame([], $this->settings->put([$key => $value]));
        $this->assertSame(0, DB::table('settings')->count());
    }

    /**
     * Days are stored furthest-out first, whatever order they were typed.
     */
    public function test_days_are_ordered(): void
    {
        $this->settings->put(['certificates.notify_at_days' => '7, 30, 1, 14']);

        $this->assertSame([30, 14, 7, 1], $this->settings->get('certificates.notify_at_days'));
    }

    /**
     * A key the service does not define is ignored rather than stored.
     *
     * The form posts a CSRF token and whatever else a browser adds.
     */
    public function test_an_unknown_key_is_ignored(): void
    {
        $this->assertSame([], $this->settings->put(['_token' => 'x', 'alerts.backlog_warning' => '250']));

        $this->assertSame(1, DB::table('settings')->count());
    }

    /**
     * Reading one is a programming error, not a missing setting.
     *
     * Returning null would make a threshold compare as zero, and every
     * backlog critical.
     */
    public function test_an_unknown_key_cannot_be_read(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->settings->get('alerts.nonesuch');
    }

    public function test_who_changed_it_is_recorded(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);
        $this->settings->put(['alerts.backlog_warning' => '250']);

        // The user id, never a copy of the email.
        $this->assertSame($admin->id, DB::table('settings')->value('updated_by'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Services\KillSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The kill switches, from the console.
 *
 * KillSwitch has been able to stop issuance, submission, clearance, reporting
 * and signing since it was written, and the only way to reach it was
 * `php artisan tinker` on a production host - at the moment of an incident,
 * composing a call with seven positional arguments, with no way to see which
 * switches were already on or who had set them.
 *
 * The reason is required by the service and asked for again by the form, so
 * an operator gets a field error rather than an exception page. Every action
 * is a POST behind data-confirm, for the reason DestructiveConfirmTest
 * records: an inline handler that breaks does not stop the form, it stops the
 * question.
 */
class KillSwitchScreenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'kill-switch-token';

    public function test_the_page_shows_every_switch(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/kill-switches');

        $response->assertOk();

        foreach (KillSwitch::SWITCHES as $switch) {
            $response->assertSee('value="'.$switch.'"', false);
        }

        $response->assertSee('Nothing is stopped');
    }

    /**
     * A switch that is on says who set it, when and why.
     *
     * The service recorded all three from the start and none of it was
     * readable, which is half of what made an incident hard to run.
     */
    public function test_an_enabled_switch_shows_its_reason(): void
    {
        app(KillSwitch::class)->enable(
            KillSwitch::SWITCH_SUBMISSION,
            reason: 'ZATCA returning 503 on every call',
            enabledBy: 'ops-on-call',
        );

        $this->actingAs($this->admin())->get('/admin/kill-switches')
            ->assertOk()
            ->assertSee('ZATCA returning 503 on every call')
            ->assertSee('ops-on-call')
            ->assertSee('turned off by hand')
            ->assertSee('Stopped');
    }

    public function test_stopping_takes_effect(): void
    {
        $this->submit('enable', ['switch' => KillSwitch::SWITCH_ISSUANCE, 'reason' => 'certificate rotation'])
            ->assertRedirect(route('admin.kill-switches'))
            ->assertSessionHas('success', 'Issuance stopped.');

        $this->assertTrue(app(KillSwitch::class)->isIssuanceBlocked());
    }

    public function test_resuming_takes_effect(): void
    {
        app(KillSwitch::class)->enable(KillSwitch::SWITCH_ISSUANCE, reason: 'certificate rotation');

        $this->submit('disable', ['switch' => KillSwitch::SWITCH_ISSUANCE])
            ->assertSessionHas('success', 'Issuance resumed.');

        $this->assertFalse(app(KillSwitch::class)->isIssuanceBlocked());
    }

    /**
     * The reason is not optional, because it is the entry someone will be
     * asked about afterwards.
     */
    public function test_stopping_needs_a_reason(): void
    {
        $this->submit('enable', ['switch' => KillSwitch::SWITCH_ISSUANCE])
            ->assertSessionHasErrors('reason');

        $this->assertFalse(app(KillSwitch::class)->isIssuanceBlocked(), 'the switch went on without a reason');
    }

    public function test_an_unknown_switch_is_refused(): void
    {
        $this->submit('enable', ['switch' => 'everything', 'reason' => 'a reason'])
            ->assertSessionHasErrors('switch');
    }

    public function test_stopping_everything_stops_everything(): void
    {
        $this->submit('stop', ['reason' => 'the authority is down'])
            ->assertSessionHas('success');

        $switches = app(KillSwitch::class);

        // Every switch except forcing offline mode, which is not a stop:
        // offline_mode makes submission queue rather than refuse, so an
        // emergency stop that set it would start accepting work.
        foreach (array_diff(KillSwitch::SWITCHES, [KillSwitch::SWITCH_OFFLINE_MODE]) as $switch) {
            $this->assertTrue($switches->isEnabled($switch), $switch.' is still running after an emergency stop');
        }

        $this->assertFalse(
            $switches->isOfflineModeForced(),
            'an emergency stop forced offline mode, which queues work instead of refusing it'
        );

        $this->submit('resume', [])->assertSessionHas('success');

        $this->assertSame([], app(KillSwitch::class)->getEnabledSwitches(), 'a switch survived resuming everything');
    }

    /**
     * Nothing here submits without asking.
     */
    public function test_every_control_asks_first(): void
    {
        app(KillSwitch::class)->enable(KillSwitch::SWITCH_SUBMISSION, reason: 'an outage');

        $page = (string) $this->actingAs($this->admin())->get('/admin/kill-switches')->getContent();

        // Every form that posts to one of these routes, by its opening tag.
        // Counting all the page's forms against all its data-confirms would
        // measure the layout as well, which is how the first version of this
        // compared 7 with 8.
        preg_match_all('/<form[^>]*action="[^"]*kill-switches[^"]*"[^>]*>/', $page, $matches);

        $this->assertNotEmpty($matches[0], 'the page offers no controls at all');

        foreach ($matches[0] as $form) {
            $this->assertStringContainsString(
                'data-confirm="',
                $form,
                'a control on this page submits without confirming: '.$form
            );
        }

        $this->assertStringNotContainsString('onsubmit', $page, 'an inline handler the content policy would refuse');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(string $action, array $payload): TestResponse
    {
        return $this->actingAs($this->admin())
            ->withSession(['_token' => self::TOKEN])
            ->post('/admin/kill-switches/'.$action, [...$payload, '_token' => self::TOKEN]);
    }

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }
}

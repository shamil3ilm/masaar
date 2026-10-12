<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * A critical event reaches somewhere a human will look.
 *
 * Seven places already raised `Log::critical` - a kill switch thrown, ZATCA
 * answering a submission the database then failed to record, an offline item
 * accepted but not written down - and every one of them went to the same file
 * as the debug noise. Nothing was wrong with the call sites; there was nowhere
 * for them to go.
 *
 * What this holds is the routing, because the routing is what will rot: an
 * alert channel is easy to drop out of a stack, and nothing fails when it
 * happens. The symptom is silence, which looks exactly like success.
 */
class AlertRoutingTest extends TestCase
{
    /**
     * Appended, not configured in.
     *
     * Whether a critical event reaches anyone is not something a deployment
     * should switch off by forgetting an entry in LOG_STACK - and it would be
     * forgotten, because a .env copied six months ago does not grow new keys.
     * The readiness guide records that exact failure for the rate-limit pair.
     */
    public function test_the_stack_always_carries_alerts(): void
    {
        $this->assertContains(
            'alerts',
            (array) config('logging.channels.stack.channels'),
            'The default log stack does not include the alert channel, so '
            .'Log::critical goes to a file and nowhere else.'
        );
    }

    /**
     * Even when LOG_STACK names something else entirely, and even when it
     * names the alert channel already - appending must not duplicate it.
     */
    public function test_a_custom_stack_still_gets_alerts(): void
    {
        foreach (['daily', 'single,daily', 'alerts', 'daily,alerts'] as $configured) {
            $channels = $this->stackFor($configured);

            $this->assertContains('alerts', $channels, "LOG_STACK={$configured} lost the alert channel.");

            $this->assertSame(
                count(array_unique($channels)),
                count($channels),
                "LOG_STACK={$configured} produced a duplicated channel, which logs every alert twice."
            );
        }
    }

    /**
     * Always a file of its own, so an alert survives a webhook that is wrong,
     * expired or rate limited. A dropped alert about a dropped submission is
     * the worst of both.
     */
    public function test_alerts_are_written_locally(): void
    {
        $this->assertContains(
            'alert-file',
            (array) config('logging.channels.alerts.channels'),
            'Alerts depend entirely on a remote service, so a webhook failure loses them.'
        );

        $this->assertStringContainsString(
            'alerts.log',
            (string) config('logging.channels.alert-file.path'),
            'Alerts share a file with ordinary logs, which is where they were already lost.'
        );
    }

    /**
     * Slack joins only when a URL is set.
     *
     * Naming an unconfigured slack channel in a stack makes every write throw,
     * so a deployment with no webhook must not get one.
     */
    public function test_slack_is_absent_until_configured(): void
    {
        $this->assertNotContains(
            'slack',
            $this->alertChannelsFor(null),
            'An unconfigured Slack channel was included, which makes every log write fail.'
        );

        $this->assertContains(
            'slack',
            $this->alertChannelsFor('https://hooks.slack.test/services/x/y/z'),
            'A configured webhook was ignored, so alerts never leave the machine.'
        );
    }

    /**
     * The webhook took its level from LOG_LEVEL, so a deployment running at
     * debug - which is the shipped default for the file channels - would have
     * sent every log line it wrote to Slack.
     */
    public function test_slack_is_pinned_to_critical(): void
    {
        $this->assertSame(
            'critical',
            (string) config('logging.channels.slack.level'),
            'The Slack channel is not pinned to critical, so routine logging reaches it.'
        );
    }

    /**
     * Re-evaluate the config file with LOG_STACK set, which is the only way
     * to test a value computed at load time.
     *
     * @return list<string>
     */
    private function stackFor(string $configured): array
    {
        return $this->evaluate(['LOG_STACK' => $configured])['channels']['stack']['channels'];
    }

    /**
     * @return list<string>
     */
    private function alertChannelsFor(?string $webhook): array
    {
        return $this->evaluate(['LOG_SLACK_WEBHOOK_URL' => $webhook])['channels']['alerts']['channels'];
    }

    /**
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    private function evaluate(array $env): array
    {
        $original = [];

        foreach ($env as $key => $value) {
            $original[$key] = $_ENV[$key] ?? null;

            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        try {
            return require base_path('config/logging.php');
        } finally {
            foreach ($original as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key], $_SERVER[$key]);
                    putenv($key);

                    continue;
                }

                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

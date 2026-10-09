<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The container receives the settings a deployment is told to set.
 *
 * docker-compose.yml's environment block is an allow-list. A variable set in
 * the host .env and not named there never reaches the container, and the
 * application reads its default instead - so nothing fails, and a setting made
 * deliberately is quietly not in force.
 *
 * That is how it stood for every one of these. The readiness guide and
 * .env.example told an operator to set the credential key and the rate limits;
 * in Docker the key would have fallen back to APP_KEY and the limits to their
 * config defaults, with no indication either way.
 *
 * A deliberately short list rather than a sweep of every env() call. These are
 * the settings a deployment is instructed to change and whose silent default
 * is a security or throughput decision taken by accident. Anything added to
 * that instruction belongs here as well.
 */
class ComposeEnvTest extends TestCase
{
    private const COMPOSE = __DIR__.'/../../../docker-compose.yml';

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function settings(): array
    {
        return [
            // Silent default: APP_KEY, which also protects sessions and
            // cookies and is held by everything.
            ['ZATCA_CREDENTIAL_KEY', 'the signing credentials would stay under APP_KEY'],
            ['ZATCA_CREDENTIAL_PREVIOUS_KEYS', 'a key rotation would make stored credentials unreadable'],
            ['ZATCA_CREDENTIAL_DISK', 'credentials would stay on a container-local disk'],

            // Silent default: a throughput ceiling nobody chose.
            ['RATE_LIMIT_DEFAULT', 'ordinary API traffic would run at the config default'],
            ['RATE_LIMIT_SUBMISSION', 'submissions would run at the config default'],
            ['ZATCA_RATE_LIMIT_PER_MINUTE', 'SubmissionGuard and the edge limiter would disagree'],
            ['RATE_LIMIT_ANONYMOUS', 'the credential endpoints would run at the config default'],

            // Silent default: errors reach a log file and nothing else.
            ['LOG_STACK', 'an alerting channel would never be written to'],
            ['LOG_SLACK_WEBHOOK_URL', 'the slack channel would have nowhere to post'],
        ];
    }

    #[DataProvider('settings')]
    public function test_the_container_receives_each_setting(string $variable, string $consequence): void
    {
        $compose = Yaml::parseFile(self::COMPOSE);
        $environment = (array) ($compose['services']['app']['environment'] ?? []);

        $this->assertArrayHasKey(
            $variable,
            $environment,
            "docker-compose.yml does not pass {$variable} to the app container, so {$consequence} "
                .'whatever the host .env says. Add it to the app service environment block.'
        );
    }

    /**
     * And each one is passed through from the host rather than pinned to a
     * literal in the compose file, or an operator editing .env changes nothing.
     */
    #[DataProvider('settings')]
    public function test_each_setting_comes_from_the_host(string $variable, string $consequence): void
    {
        $compose = Yaml::parseFile(self::COMPOSE);
        $value = (string) (((array) ($compose['services']['app']['environment'] ?? []))[$variable] ?? '');

        $this->assertStringContainsString(
            '${'.$variable,
            $value,
            "docker-compose.yml pins {$variable} to \"{$value}\" instead of reading it from the "
                .'host environment, so setting it in .env has no effect.'
        );
    }
}

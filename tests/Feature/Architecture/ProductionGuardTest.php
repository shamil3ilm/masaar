<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The file every deployment copies passes the checks every deployment runs.
 *
 * AppServiceProvider refuses to boot a production deployment in three
 * conditions, each chosen because the code is right and the .env is what
 * drifts. The flaw in that reasoning is that nothing checked the .env the
 * drift starts from, and `.env.example` declares APP_ENV=production itself -
 * so it is not a neutral template, it is a production configuration, and it
 * has to satisfy the guards it would be subject to.
 *
 * It did not. It set SESSION_DRIVER=database with SESSION_ENCRYPT=false,
 * which puts the licence API secret the console flashes after issuing one
 * into a row of the sessions table in the clear. config/session.php already
 * defaulted that flag to true under production; the example turned the safe
 * default off, which is the worst place for it to be wrong.
 *
 * Asserted by reading the file rather than by booting. A boot guard cannot be
 * exercised from inside a booted application, and the thing worth checking is
 * the document, not the mechanism.
 */
class ProductionGuardTest extends TestCase
{
    private const EXAMPLE = __DIR__.'/../../../.env.example';

    public function test_the_example_satisfies_every_guard(): void
    {
        $env = $this->example();

        $this->assertSame('production', $env['APP_ENV'] ?? null,
            'This test assumes the example describes a production deployment. If that '
            .'changed, the guards no longer apply to it and this should say so.');

        // assertDebugDisabledInProduction: APP_DEBUG on makes the catch-all
        // API renderer return exception messages to the caller.
        $this->assertSame('false', $env['APP_DEBUG'] ?? null,
            'APP_DEBUG is not false, and the application refuses to boot like that.');

        // assertSessionsAreEncrypted: the console flashes a one-time API
        // secret through the session.
        $this->assertSame('true', $env['SESSION_ENCRYPT'] ?? null,
            'SESSION_ENCRYPT is not true, so with SESSION_DRIVER='
            .($env['SESSION_DRIVER'] ?? '?').' a licence secret would be stored in the clear.');

        // assertSubmissionLimitsAgree: two keys throttle submissions and the
        // lower one silently decides.
        $this->assertSame($env['RATE_LIMIT_SUBMISSION'] ?? 'a', $env['ZATCA_RATE_LIMIT_PER_MINUTE'] ?? 'b',
            'The edge limit and the guard limit disagree, so the lower one decides and '
            .'the other is documentation for a limit not in force.');
    }

    /**
     * And a guard added later is covered here.
     *
     * The three above were written from reading the provider, which is a
     * method that stops working the moment somebody adds a fourth. This
     * counts them instead, so a new guard fails this test until the example
     * is checked against it.
     */
    public function test_every_guard_is_checked_above(): void
    {
        $guards = array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(AppServiceProvider::class))->getMethods(ReflectionMethod::IS_PRIVATE),
                fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), 'assert')
            )
        );

        sort($guards);

        $this->assertSame([
            'assertDebugDisabledInProduction',
            'assertSessionsAreEncrypted',
            'assertSubmissionLimitsAgree',
        ], $guards, 'AppServiceProvider has a boot guard this test does not check the '
            .'shipped .env.example against. Add the assertion above, then name it here.');
    }

    /**
     * @return array<string, string>
     */
    private function example(): array
    {
        $contents = file_get_contents(self::EXAMPLE);

        self::assertNotFalse($contents, '.env.example is missing.');

        $values = [];

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $m) === 1) {
                $values[$m[1]] = trim($m[2], " \"'");
            }
        }

        return $values;
    }
}

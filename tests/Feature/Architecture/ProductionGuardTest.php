<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * Both files are checked. docker/.env.template declares APP_ENV=production
 * too, so a container deployment is subject to the same guards, and it was
 * not looked at.
 *
 * .env.example did not pass. It set SESSION_DRIVER=database with SESSION_ENCRYPT=false,
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
    /**
     * Both files a deployment is told to copy. Each declares
     * APP_ENV=production itself, so each is a production configuration
     * subject to the guards rather than a neutral template.
     *
     * @var list<string>
     */
    private const TEMPLATES = ['.env.example', 'docker/.env.template'];

    /**
     * @return list<array{0: string}>
     */
    public static function templates(): array
    {
        return array_map(fn (string $path): array => [$path], self::TEMPLATES);
    }

    #[DataProvider('templates')]
    public function test_each_template_satisfies_the_guards(string $template): void
    {
        $env = $this->settingsIn($template);

        $this->assertSame('production', $env['APP_ENV'] ?? null,
            $template.' does not describe a production deployment. If that changed, the '
            .'guards no longer apply to it and this should say so.');

        // A setting left out takes its config default, and for all three
        // guards that default is the safe one: APP_DEBUG is false,
        // session.encrypt is true under production, and the two rate limits
        // are the same number. So absence passes and a wrong value does not.

        // assertDebugDisabledInProduction: APP_DEBUG on makes the catch-all
        // API renderer return exception messages to the caller.
        if (array_key_exists('APP_DEBUG', $env)) {
            $this->assertSame('false', $env['APP_DEBUG'],
                $template.' sets APP_DEBUG='.$env['APP_DEBUG']
                .', and the application refuses to boot like that.');
        }

        // assertSessionsAreEncrypted: the console flashes a one-time API
        // secret through the session.
        if (array_key_exists('SESSION_ENCRYPT', $env)) {
            $this->assertSame('true', $env['SESSION_ENCRYPT'],
                $template.' sets SESSION_ENCRYPT='.$env['SESSION_ENCRYPT'].', so with '
                .'SESSION_DRIVER='.($env['SESSION_DRIVER'] ?? 'its default')
                .' a licence secret would be stored in the clear.');
        }

        // assertSubmissionLimitsAgree: two keys throttle submissions and the
        // lower one silently decides. Either both are set and equal, or
        // neither is and they share a default.
        $edge = $env['RATE_LIMIT_SUBMISSION'] ?? null;
        $guard = $env['ZATCA_RATE_LIMIT_PER_MINUTE'] ?? null;

        $this->assertSame($edge, $guard,
            $template.' sets the edge limit to '.var_export($edge, true).' and the guard '
            .'limit to '.var_export($guard, true).'. Set apart, the lower one decides and '
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
    private function settingsIn(string $template): array
    {
        $contents = file_get_contents(__DIR__.'/../../../'.$template);

        self::assertNotFalse($contents, $template.' is missing.');

        $values = [];

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $m) === 1) {
                $values[$m[1]] = trim($m[2], " \"'");
            }
        }

        return $values;
    }
}

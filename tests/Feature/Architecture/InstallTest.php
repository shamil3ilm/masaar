<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `composer install` works before an environment exists.
 *
 * Composer's post-autoload-dump runs `artisan package:discover`, which boots
 * the application - and with it the production guards in
 * AppServiceProvider::boot(). It runs before any .env is written, so the
 * defaults apply: APP_ENV falls back to 'production' and ZATCA_ENVIRONMENT to
 * 'sandbox', which is exactly the pairing assertAuthorityIsNotSandbox refuses.
 *
 * So adding that guard broke `composer install` itself. CI runs it before its
 * "Prepare environment" step and failed at "Install dependencies" on every
 * push from that commit on - thirty-two runs in which not one test ran -
 * while the suite was being reported green from a local SQLite run, where a
 * .env already existed and nothing installed from scratch.
 *
 * Both halves are asserted, because the obvious fix for the first breaks the
 * second: discovery has to get through, and a command that actually runs the
 * platform still has to be stopped.
 *
 * In a subprocess, because the guards run at boot and this suite has already
 * booted. The variables are passed explicitly so a developer's own .env
 * cannot make this pass or fail: a variable in the process environment is not
 * overwritten by .env.
 */
class InstallTest extends TestCase
{
    /**
     * What a fresh machine looks like to the application: no .env, so every
     * value is its default.
     */
    private const UNCONFIGURED = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        // config/session.php derives this from APP_ENV when it is unset.
        'SESSION_ENCRYPT' => 'true',
        'ZATCA_ENVIRONMENT' => 'sandbox',
        'UAE_FTA_ENVIRONMENT' => 'sandbox',
    ];

    public function test_install_survives_no_environment(): void
    {
        $process = $this->invoke('package:discover');

        $this->assertSame(0, $process->getExitCode(),
            "package:discover failed with no environment configured, so composer install would too:\n"
            .$process->getOutput().$process->getErrorOutput());
    }

    /**
     * And the guard is still there for everything that is not discovery.
     */
    public function test_a_real_command_is_still_guarded(): void
    {
        $process = $this->invoke('about');

        $this->assertNotSame(0, $process->getExitCode(), 'a production platform booted pointing at the sandbox');
        $this->assertStringContainsString(
            'ZATCA_ENVIRONMENT must not be sandbox',
            $process->getOutput().$process->getErrorOutput()
        );
    }

    private function invoke(string $command): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', $command, '--no-ansi'],
            base_path(),
            self::UNCONFIGURED,
        );

        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}

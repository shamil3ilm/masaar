<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Every command referenced by name must actually be registered.
 *
 * The CompliPay -> Masaar rebrand renamed the command signatures from zatca:*
 * to fatoora:* but left routes/console.php scheduling the old names. Laravel
 * reports nothing for a scheduled command that does not exist, so three jobs
 * stopped running with no error anywhere: the offline queue never drained,
 * certificate expiry was never checked, and the hash chain was never verified.
 *
 * Two licensing commands were unregistered for a different reason — they live
 * in app/Domains/Licensing/Console, and Laravel only auto-discovers
 * app/Console/Commands.
 *
 * A string referring to a command is a dependency the compiler cannot see, so
 * it gets a test instead.
 */
class ScheduledCommandTest extends TestCase
{
    /**
     * Commands invoked by name from application code.
     */
    private const CALLED_IN_CODE = [
        'fatoora:process-offline',
        'compliance:index-health',
    ];

    public function test_scheduled_commands_exist(): void
    {
        $registered = array_keys(Artisan::all());
        $missing = [];

        foreach (app(Schedule::class)->events() as $event) {
            $name = $this->commandName($event->command ?? '');

            if ($name !== null && ! in_array($name, $registered, true)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'Scheduled but not registered: '.implode(', ', $missing));
    }

    public function test_commands_called_from_code_exist(): void
    {
        $registered = array_keys(Artisan::all());

        foreach (self::CALLED_IN_CODE as $command) {
            $this->assertContains(
                $command,
                $registered,
                "Artisan::call('{$command}') refers to a command that is not registered."
            );
        }
    }

    /**
     * The licensing commands sit outside app/Console/Commands, so they are only
     * present if bootstrap/app.php lists their directory.
     */
    public function test_domain_commands_are_registered(): void
    {
        $registered = array_keys(Artisan::all());

        foreach (['license:check-expiration', 'license:cleanup-rate-limits'] as $command) {
            $this->assertContains(
                $command,
                $registered,
                "{$command} is not registered. Domain console directories must be "
                .'listed in bootstrap/app.php withCommands().'
            );
        }
    }

    /**
     * Extracts the command name from a scheduled event's full CLI string,
     * which looks like: '"php" "artisan" name --option'.
     */
    private function commandName(string $command): ?string
    {
        if (! preg_match_all('/"([^"]+)"|(\S+)/', $command, $parts, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($parts as $part) {
            $token = $part[1] !== '' ? $part[1] : ($part[2] ?? '');

            if ($token === '' || str_contains($token, 'php') || str_contains($token, 'artisan')) {
                continue;
            }

            return str_starts_with($token, '-') ? null : $token;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Every command referenced by name must actually be registered.
 *
 * Laravel reports nothing for a scheduled command that does not exist, so a
 * signature that drifts from its caller takes the job out of service silently.
 * The offline queue stops draining, certificates stop being checked for
 * expiry, and the hash chain stops being verified — with nothing in any log.
 *
 * Registration is the other half: Laravel auto-discovers only
 * app/Console/Commands, so a command living in a domain is absent from the
 * registry unless bootstrap/app.php lists its directory.
 *
 * A command named by string is a dependency the compiler cannot check, so it
 * gets a test instead.
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

    /**
     * Every replica runs the scheduler, so every task runs on one of them.
     *
     * withoutOverlapping() only stops two copies running at the same moment.
     * A replica that finished a task before the other replica's minute came
     * round ran it again: tenants were told twice that a certificate expires,
     * usage was reported twice to the licence server it is billed from, and
     * the alerts paged twice. onOneServer() takes one lock per task per run
     * in the shared cache, and the replica that loses does nothing.
     */
    public function test_every_task_runs_on_one_server(): void
    {
        $everywhere = [];

        foreach (app(Schedule::class)->events() as $event) {
            if ($event->onOneServer !== true) {
                $everywhere[] = $event->command;
            }
        }

        $this->assertSame([], $everywhere, "These tasks run on every replica:\n  ".implode("\n  ", $everywhere));
    }

    /**
     * A task that guards against overlapping says for how long.
     *
     * withoutOverlapping() defaults to a 1440 minute expiry. A
     * runInBackground() child killed by OOM, a docker stop or a deploy leaves
     * the mutex held, and the task is then blocked for twenty-four hours with
     * nothing logged. fatoora:process-offline runs every five minutes and
     * drains the offline queue, and simplified invoices have twenty-four
     * hours to be reported - so the default is precisely one reporting
     * deadline of silence.
     *
     * Asserted on the schedule rather than the source, because the default is
     * invisible there: the absence of an argument is what causes it.
     */
    public function test_an_overlap_guard_states_its_expiry(): void
    {
        $defaulted = [];

        foreach (app(Schedule::class)->events() as $event) {
            // Only the tasks that actually take the mutex.
            if ($event->withoutOverlapping !== true) {
                continue;
            }

            if ($event->expiresAt === 1440) {
                $defaulted[] = $this->commandName($event->command ?? '') ?? $event->description ?? 'a closure';
            }
        }

        $this->assertSame([], $defaulted, 'these hold the overlap mutex for 24 hours if their run dies: '
            .implode(', ', $defaulted));
    }

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

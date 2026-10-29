<?php

declare(strict_types=1);

namespace App\Domains\Organization\Support;

use App\Domains\Organization\Attributes\RunsAcrossTenants;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Tells TenantResolver when a command or a queued job runs, and whether it
 * declared that it runs across tenants (#[RunsAcrossTenants]); TenantScope
 * refuses the scoped queries of one that declared neither that nor a tenant.
 *
 * Every queued job is held to it, and every command of the application's
 * own. The framework's and packages' commands - migrate, db:seed, tinker,
 * model:prune, schedule:run, queue:work itself - are not: they name no
 * tenant model of their own, and what they run (a seeder, a scheduled
 * command, a job) is held to it where it is.
 */
final class TenantWork
{
    private static ?Artisan $artisan = null;

    /** Called once, from the service provider. */
    public static function listen(): void
    {
        // The console application, to find the command a name runs: the one
        // it is about to run, so nothing is loaded that would not be.
        Artisan::starting(function (Artisan $artisan): void {
            self::$artisan = $artisan;
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = self::command($event->command);
            $own = $command !== null && ! str_contains(str_replace('\\', '/', (string) (new ReflectionClass($command))->getFileName()), '/vendor/');

            app(TenantResolver::class)->startWork('command:'.spl_object_id($event->input), (string) $event->command, ! $own || self::declaresAcrossTenants($command::class));
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            app(TenantResolver::class)->finishWork('command:'.spl_object_id($event->input));
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            // The job's class, or a queued listener's or mailable's: what its
            // payload calls it, as the worker's own log line does.
            $payload = $event->job->payload();
            $name = (string) strtok((string) ($payload['displayName'] ?? $payload['job'] ?? 'job'), '@');

            app(TenantResolver::class)->startWork('job:'.spl_object_id($event->job), $name, self::declaresAcrossTenants($name));
        });

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $finished) {
            Event::listen($finished, function (JobProcessed|JobFailed|JobExceptionOccurred $event): void {
                app(TenantResolver::class)->finishWork('job:'.spl_object_id($event->job));
            });
        }
    }

    /** The command a name runs, or null for none, or one it cannot tell. */
    private static function command(?string $name): ?SymfonyCommand
    {
        try {
            return $name === null ? null : self::$artisan?->find($name);
        } catch (Throwable) {
            // An unknown or ambiguous name: the console answers that itself.
            return null;
        }
    }

    private static function declaresAcrossTenants(string $class): bool
    {
        return class_exists($class) && (new ReflectionClass($class))->getAttributes(RunsAcrossTenants::class) !== [];
    }
}

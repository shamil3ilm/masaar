<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\Check;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\Rehearsal;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\RehearsalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Console\Command;
use Throwable;

/**
 * The ZATCA journey a pilot will take, rehearsed on a deployed server
 * against the sandbox before the pilot signs in (docs/operations/runbooks/
 * PILOT-REHEARSAL.md).
 *
 * Only ever the rehearsal organization (RehearsalOrganization), and only
 * while it works in the sandbox; no other organization is read or written.
 * Prints a PASS or FAIL line per step and exits non-zero on any FAIL.
 *
 * Nothing is deleted. Without --keep the rehearsal organization is
 * suspended afterwards with a reason that says what it is, so nothing is
 * issued under it by mistake; the next run reactivates it.
 */
class PlatformRehearse extends Command
{
    protected $signature = 'platform:rehearse
                            {--admin= : Email of the platform admin running it, recorded as who approved and suspended the rehearsal organization}
                            {--keep : Leave the rehearsal organization active afterwards, to inspect it in the browser}
                            {--wait=120 : Seconds to wait for a queue worker to file a simplified document}';

    protected $description = 'Rehearse onboarding, issuing and filing against the ZATCA sandbox with the rehearsal organization';

    public function handle(RehearsalOrganization $organizations, Rehearsal $rehearsal, TenantResolver $tenants): int
    {
        $admin = User::where('email', (string) $this->option('admin'))->first();

        if ($admin === null || ! $admin->isPlatformAdmin()) {
            $this->error('Name an active platform admin with --admin=<email>.');

            return self::FAILURE;
        }

        try {
            $organization = $organizations->prepare((string) $admin->id);
        } catch (Throwable $e) {
            $this->error('Refusing to run: '.$e->getMessage());

            return self::FAILURE;
        }

        $run = now()->format('YmdHis');
        $this->info("Rehearsal {$run} for {$organization->name} ({$organization->id}), in the sandbox.");

        // As the rehearsal organization, held to its rows (TenantScope).
        $checks = $tenants->runAs((string) $organization->id, fn (): array => $rehearsal->run($organization, $run, max(0, (int) $this->option('wait')), function (Check $check): void {
            $this->line(($check->passed ? '  PASS  ' : '  FAIL  ').$check->name);
        }));

        $this->newLine();
        $this->table(['Step', 'Result', 'Detail', 'Fix'], array_map(fn (Check $check): array => $check->row(), $checks));

        if ($this->option('keep')) {
            $this->line('Kept active for inspection (--keep). Run platform:rehearse again, or suspend it from the console, when done.');
        } else {
            $organizations->close($organization, (string) $admin->id);
            $this->line('The rehearsal organization is suspended until the next rehearsal; everything it issued is kept.');
        }

        $failed = count(array_filter($checks, fn (Check $check): bool => ! $check->passed));

        if ($failed > 0) {
            $this->error("FAILED: {$failed} of ".count($checks).' steps.');

            return self::FAILURE;
        }

        $this->info('PASSED: all '.count($checks).' steps.');

        return self::SUCCESS;
    }
}

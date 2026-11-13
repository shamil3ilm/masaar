<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Compliance\Countries\SaudiArabia\Pilot\Check;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\SimulationPreflight;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Console\Command;

/**
 * Before a platform admin switches a real organization to ZATCA's
 * simulation environment: each check PASS or FAIL, with the fix
 * (SimulationPreflight; docs/operations/runbooks/PILOT-SIMULATION.md). Changes
 * nothing; exits non-zero when anything fails.
 */
class CheckSimulationReadiness extends Command
{
    protected $signature = 'platform:simulation-preflight
                            {organization? : The organization\'s id or VAT number}
                            {--system-only : Run only system, server, and gateway connectivity checks}
                            {--send-test-mail= : Also send a test message to this address}';

    protected $description = 'Check, without changing anything, that an organization and this server are ready for ZATCA\'s simulation environment';

    public function handle(SimulationPreflight $preflight, TenantResolver $tenants): int
    {
        $address = $this->option('send-test-mail');

        if ($address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("{$address} is not an email address.");

            return self::FAILURE;
        }

        if ($this->option('system-only')) {
            $this->info('Running system preflight checks (server, mail, queue, scheduler, gateway, encryption, audit)...');
            $checks = $preflight->systemChecks($address);
            $this->table(['Check', 'Result', 'Detail', 'Fix'], array_map(fn (Check $check): array => $check->row(), $checks));

            $failed = count(array_filter($checks, fn (Check $check): bool => ! $check->passed));

            if ($failed > 0) {
                $this->error("NOT READY: {$failed} of ".count($checks).' system checks failed. Fix them before proceeding.');

                return self::FAILURE;
            }

            $this->info('READY: all '.count($checks).' system checks passed.');

            return self::SUCCESS;
        }

        $given = (string) $this->argument('organization');

        if (blank($given)) {
            $this->error('Please specify an organization id or VAT number, or pass --system-only.');

            return self::FAILURE;
        }

        $found = Organization::query()->whereKey($given)->orWhere('vat_number', $given)->limit(2)->get();

        if ($found->count() !== 1) {
            $this->error($found->isEmpty() ? "No organization has the id or VAT number {$given}." : "More than one organization has the VAT number {$given}: give its id.");

            return self::FAILURE;
        }

        $organization = $found->first();

        if ($organization->country !== 'SA') {
            $this->error("{$organization->name} is not registered in Saudi Arabia.");

            return self::FAILURE;
        }

        $this->info("{$organization->name} ({$organization->id}), now in the ".FatooraEnvironment::label(FatooraEnvironment::of($organization)).' environment.');

        // As the organization, held to its rows (TenantScope).
        $checks = $tenants->runAs((string) $organization->id, fn (): array => $preflight->run($organization, $address));
        $this->table(['Check', 'Result', 'Detail', 'Fix'], array_map(fn (Check $check): array => $check->row(), $checks));

        $failed = count(array_filter($checks, fn (Check $check): bool => ! $check->passed));

        if ($failed > 0) {
            $this->error("NOT READY: {$failed} of ".count($checks).' checks failed. Fix them before switching it to simulation.');

            return self::FAILURE;
        }

        $this->info('READY: all '.count($checks).' checks passed. A platform admin can switch it to simulation now.');

        return self::SUCCESS;
    }
}

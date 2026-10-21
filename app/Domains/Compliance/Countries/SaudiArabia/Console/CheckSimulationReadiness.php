<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Compliance\Countries\SaudiArabia\Pilot\Check;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\SimulationPreflight;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Organization\Models\Organization;
use Illuminate\Console\Command;

/**
 * Before a platform admin switches a real organization to ZATCA's
 * simulation environment: each check PASS or FAIL, with the fix
 * (SimulationPreflight; docs/runbooks/PILOT-SIMULATION.md). Changes
 * nothing; exits non-zero when anything fails.
 */
class CheckSimulationReadiness extends Command
{
    protected $signature = 'platform:simulation-preflight
                            {organization : The organization\'s id or VAT number}
                            {--send-test-mail= : Also send a test message to this address}';

    protected $description = 'Check, without changing anything, that an organization and this server are ready for ZATCA\'s simulation environment';

    public function handle(SimulationPreflight $preflight): int
    {
        $given = (string) $this->argument('organization');
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

        $address = $this->option('send-test-mail');

        if ($address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("{$address} is not an email address.");

            return self::FAILURE;
        }

        $this->info("{$organization->name} ({$organization->id}), now in the ".FatooraEnvironment::label(FatooraEnvironment::of($organization)).' environment.');

        $checks = $preflight->run($organization, $address);
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

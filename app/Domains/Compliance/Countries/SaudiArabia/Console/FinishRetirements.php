<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\TillRetiredMail;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\UnitRetirement;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Retires every retiring till whose documents are all filed.
 *
 * A till asked to retire with documents still to file stops issuing and
 * stays retiring until ZATCA holds them (UnitRetirement). Nobody had to ask
 * again: this runs daily, retires each one that has nothing left, records
 * it in its organization's audit log, and tells the organization's
 * administrators.
 */
class FinishRetirements extends Command
{
    protected $signature = 'fatoora:finish-retirements';

    protected $description = 'Retire every retiring till whose documents are all filed';

    public function __construct(
        private readonly UnitRetirement $retirement,
        private readonly Tills $tills,
        private readonly ThemeService $themes,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $retiring = EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->with('branch:id,name')->where('status', EgsUnit::RETIRING)->get());
        $retired = 0;

        foreach ($retiring as $unit) {
            try {
                if ($this->retirement->finish($unit)) {
                    $retired++;
                    $this->tell($unit);
                }
            } catch (Throwable $e) {
                // One till that cannot be finished leaves the others to be.
                Log::error('A retiring till could not be retired', ['org_id' => $unit->org_id, 'device_id' => $unit->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Retiring: {$retiring->count()}. Retired now: {$retired}.");

        return self::SUCCESS;
    }

    /** The organization's administrators, one mail each in their own language. */
    private function tell(EgsUnit $unit): void
    {
        $organization = Organization::query()->find($unit->org_id);

        if ($organization === null) {
            return;
        }

        $brand = $this->themes->forScope((string) $organization->id);
        $till = $this->tills->label($unit);

        $admins = $organization->users()
            ->wherePivotIn('role', MemberRole::ADMINISTERING)
            ->wherePivot('status', 'active')
            ->get();

        foreach ($admins as $admin) {
            Mail::to($admin)->send(new TillRetiredMail(
                organizationName: (string) $organization->name,
                tillName: $till,
                brand: $brand,
            ));
        }
    }
}

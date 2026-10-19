<?php

declare(strict_types=1);

namespace App\Domains\Auth\Console;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\EnrolmentLinks;
use App\Domains\Auth\Services\TwoFactor;
use Illuminate\Console\Command;

/**
 * An enrolment link from the server's own console.
 *
 * For when no other platform admin can issue one: the first admin, every
 * admin who had not enrolled when links became required, and a sole admin
 * who has lost their phone and their recovery codes (--reset). Whoever can
 * run this already controls the server, so it grants nothing new.
 */
class IssueEnrolmentLink extends Command
{
    protected $signature = 'platform:enrolment-link
                            {email : The platform admin to issue it for}
                            {--reset : Clear their two-factor first, for one who has lost it}';

    protected $description = 'Issue a one-time two-factor enrolment link for a platform admin';

    public function handle(EnrolmentLinks $links, TwoFactor $twoFactor, AuditService $audit): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();

        if ($user === null || ! $user->isPlatformAdmin()) {
            $this->error('No active platform admin has that address.');

            return self::FAILURE;
        }

        if ($user->hasTwoFactor()) {
            if (! $this->option('reset')) {
                $this->error($user->email.' already has two-factor. Re-run with --reset if they have lost it.');

                return self::FAILURE;
            }

            $twoFactor->reset($user);
            $audit->logSecurity('mfa.reset', 'User', (string) $user->id, ['reset_by_os_user' => get_current_user()]);
            $this->warn('Two-factor cleared for '.$user->email.'.');
        }

        $this->line('Open this link, then sign in and set up two-factor. It works once and expires in '
            .EnrolmentLinks::VALID_HOURS.' hours:');

        if (($warning = EnrolmentLinks::unreachableHostWarning()) !== null) {
            $this->warn($warning);
        }

        $this->line($links->issue($user, null));

        return self::SUCCESS;
    }
}

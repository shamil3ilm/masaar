<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Mail\RenewalReminderMail;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Telling an organization its plan ends soon, while there is time to pay.
 *
 * A licence that lapses stops its organization issuing, and the first anyone
 * heard of it was an invoice refused at the till. Each configured day before
 * the end, the organization's admins are emailed - once, because the run is
 * daily and each reminder day is one calendar day wide.
 */
class RenewalReminders
{
    /**
     * @return int How many reminders were sent
     */
    public function send(): int
    {
        $sent = 0;

        foreach ((array) config('billing.reminder_days', [14, 3]) as $days) {
            $day = now()->addDays((int) $days);

            $licences = License::query()
                ->where('status', LicenseStatus::Active)
                ->whereNotNull('org_id')
                ->whereBetween('expires_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                ->get();

            foreach ($licences as $licence) {
                $sent += $this->remind($licence, (int) $days);
            }
        }

        return $sent;
    }

    private function remind(License $licence, int $days): int
    {
        $organization = Organization::query()->find($licence->org_id);

        if ($organization === null) {
            return 0;
        }

        $admins = $organization->users()
            ->wherePivot('role', 'admin')
            ->wherePivot('status', 'active')
            ->where('users.status', 'active')
            ->get();

        $sent = 0;

        // One each, so each is written in its reader's language.
        foreach ($admins as $admin) {
            try {
                Mail::to($admin)->send(new RenewalReminderMail(
                    organizationName: (string) $organization->name,
                    plan: $licence->tier->getDisplayName(),
                    endsOn: (string) $licence->expires_at?->toDateString(),
                    days: $days,
                    url: route('portal.billing'),
                ));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}

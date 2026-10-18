<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\Mail\AllowanceWarningMail;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Telling a customer it is near or at an allowance it has been given.
 *
 * An allowance - invoices a month, API calls a day - is the customer's own,
 * set by a platform admin, and unlimited unless set. A set one warns the
 * organization's administrators at each threshold of
 * licensing.notifications.quota_warning_percent (80% and 100%), once each a
 * period: the allowance_warnings unique key decides who sends, so requests
 * crossing a threshold together send one email. An unlimited allowance is
 * never warned about and never blocks.
 */
class AllowanceWarnings
{
    /**
     * After usage changed: warn at the highest threshold now reached, if this
     * period has not been warned at it.
     *
     * @param  string  $allowance  'invoices_per_month' or 'calls_per_day'
     */
    public function check(License $licence, string $allowance, int $used): void
    {
        if ($licence->isUnlimited($allowance)) {
            return;
        }

        $allowed = (int) $licence->getAttribute($allowance);

        if ($allowed <= 0) {
            return;
        }

        $percent = (int) floor($used * 100 / $allowed);
        $reached = array_filter((array) config('licensing.notifications.quota_warning_percent', [80, 100]), fn ($threshold): bool => $percent >= (int) $threshold);

        if ($reached === []) {
            return;
        }

        $threshold = (int) max($reached);
        $period = $allowance === 'calls_per_day' ? now()->format('Y-m-d') : now()->format('Y-m');

        $first = DB::table('allowance_warnings')->insertOrIgnore([
            'license_id' => $licence->id,
            'allowance' => $allowance,
            'period' => $period,
            'percent' => $threshold,
            'used' => $used,
            'allowed' => $allowed,
            'warned_at' => now(),
        ]);

        if ($first === 0) {
            return;
        }

        Log::warning('Allowance warning', ['license_id' => $licence->id, 'allowance' => $allowance, 'percent' => $threshold, 'used' => $used, 'allowed' => $allowed]);

        $this->mail($licence, $allowance, $threshold, $used, $allowed);
    }

    private function mail(License $licence, string $allowance, int $threshold, int $used, int $allowed): void
    {
        $organization = $licence->org_id === null ? null : Organization::query()->find($licence->org_id);

        if ($organization === null) {
            return;
        }

        $admins = $organization->users()
            ->wherePivot('role', 'admin')
            ->wherePivot('status', 'active')
            ->where('users.status', 'active')
            ->get();

        // One each, so each is written in its reader's language.
        foreach ($admins as $admin) {
            try {
                Mail::to($admin)->send(new AllowanceWarningMail(
                    organizationName: (string) $organization->name,
                    allowance: $allowance,
                    percent: $threshold,
                    used: $used,
                    allowed: $allowed,
                    url: route('portal.billing'),
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}

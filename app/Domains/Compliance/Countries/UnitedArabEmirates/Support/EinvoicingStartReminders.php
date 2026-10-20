<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Support;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Mail\EinvoicingStartReminderMail;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Reminds a UAE organization whose e-invoicing start date is near and that
 * has no service provider appointed: from that date every invoice goes
 * through one (UAE Electronic Invoicing Guidelines v1.1, 6.1), and issuing
 * is refused without it.
 *
 * At 90, 30 and 7 days before the date, once each. The reminder sent is the
 * nearest threshold not yet passed, so a date set 20 days ahead is reminded
 * at 30 and then at 7, not at every threshold it missed; the
 * einvoicing_start_reminders unique key makes each go out once per date,
 * however often this runs.
 */
final class EinvoicingStartReminders
{
    /** Days before the start date, furthest first. */
    public const THRESHOLDS = [90, 30, 7];

    public function __construct(
        private readonly EinvoicingStart $start,
        private readonly ProviderAppointments $appointments,
    ) {}

    /**
     * The threshold the organization is within now, if it must be reminded:
     * a start date ahead of it and no provider appointed. The dashboard's
     * notice and the email both ask this.
     *
     * @return array{date: CarbonImmutable, days: int, threshold: int}|null
     */
    public function due(Organization $organization): ?array
    {
        $date = $this->start->date($organization);

        if ($date === null || $this->appointments->appointed((string) $organization->id)) {
            return null;
        }

        // Signed: a date already past is negative and is no reminder's.
        $days = (int) CarbonImmutable::now(EinvoicingStart::TIMEZONE)->startOfDay()->diffInDays($date, false);
        $threshold = null;

        foreach (self::THRESHOLDS as $candidate) {
            if ($days > 0 && $days <= $candidate) {
                $threshold = $candidate;
            }
        }

        return $threshold === null ? null : ['date' => $date, 'days' => $days, 'threshold' => $threshold];
    }

    /**
     * Email the administrators of every active UAE organization that is due.
     *
     * @return int how many reminders were sent
     */
    public function remind(): int
    {
        $sent = 0;

        foreach (Organization::query()->where('country', 'AE')->where('status', 'active')->get() as $organization) {
            $due = $this->due($organization);

            if ($due === null || ! $this->claim($organization, $due['date'], $due['threshold'])) {
                continue;
            }

            foreach ($this->appointments->administrators($organization) as $admin) {
                // One each, so each is written in its reader's language.
                try {
                    Mail::to($admin)->send(new EinvoicingStartReminderMail(
                        organizationName: (string) $organization->name,
                        startsOn: $due['date']->toDateString(),
                        days: $due['threshold'],
                        url: route('portal.company.edit'),
                    ));
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $sent;
    }

    /** Whether this run is the one that reminds at this threshold for this date. */
    private function claim(Organization $organization, CarbonImmutable $date, int $threshold): bool
    {
        return DB::table('einvoicing_start_reminders')->insertOrIgnore([
            'org_id' => $organization->id,
            'starts_on' => $date->toDateString(),
            'days_before' => $threshold,
            'reminded_at' => now(),
        ]) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Support;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Mail\ProviderAppointmentReminderMail;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Models\ProviderAppointment;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspGatewayResolver;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A UAE organization's appointment of its accredited service provider: the
 * record, whether it is appointed, by when it must be, and reminders.
 *
 * A person in scope must appoint one provider for sending and receiving
 * (UAE Electronic Invoicing Guidelines v1.1, p14), contract with it and
 * onboard through EmaraTax (p38-39), by the last date its revenue band sets
 * (p19). The date is configuration (peppol.countries.AE.appointment.deadline)
 * or the organization's own, never the code's.
 *
 * Recording an appointment also points the organization's documents at that
 * provider (AspGatewayResolver's customization), so the record and the
 * routing cannot disagree.
 */
final class ProviderAppointments
{
    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    /** The appointment in force or pending, if any. */
    public function current(string $organizationId): ?ProviderAppointment
    {
        return ProviderAppointment::acrossTenants()
            ->where('org_id', $organizationId)
            ->where('status', '!=', ProviderAppointment::ENDED)
            ->latest('created_at')
            ->first();
    }

    public function appointed(string $organizationId): bool
    {
        return $this->current($organizationId)?->status === ProviderAppointment::APPOINTED;
    }

    /**
     * The last date to appoint: the organization's own, else the platform's.
     */
    public function deadline(string $organizationId): ?CarbonImmutable
    {
        $own = $this->current($organizationId)?->deadline_on;

        if ($own !== null) {
            return CarbonImmutable::parse($own)->startOfDay();
        }

        $platform = config('peppol.countries.AE.appointment.deadline');

        return is_string($platform) && $platform !== '' ? CarbonImmutable::parse($platform)->startOfDay() : null;
    }

    /**
     * Record what the organization appointed. The same provider updates the
     * current record; another ends it and starts a new one, so the history
     * of appointments stays.
     *
     * @param  array{provider: string, status: string, appointed_at?: string|null, reference?: string|null, deadline_on?: string|null}  $data
     */
    public function record(Organization $organization, array $data, ?string $actorId): ProviderAppointment
    {
        return DB::transaction(function () use ($organization, $data, $actorId): ProviderAppointment {
            $current = $this->current((string) $organization->id);
            $values = [
                'status' => $data['status'],
                'appointed_at' => $data['status'] === ProviderAppointment::APPOINTED ? ($data['appointed_at'] ?? now()) : null,
                'reference' => $data['reference'] ?? null,
                'deadline_on' => $data['deadline_on'] ?? null,
            ];

            if ($current !== null && $current->provider === $data['provider']) {
                $current->update($values);
                $appointment = $current;
            } else {
                $current?->update(['status' => ProviderAppointment::ENDED, 'ended_at' => now()]);
                $appointment = ProviderAppointment::acrossTenants()->create([
                    ...$values,
                    'org_id' => $organization->id,
                    'provider' => $data['provider'],
                ]);
            }

            $this->customizations->set(
                AspGatewayResolver::SETTING,
                Level::Organization,
                Scope::organization((string) $organization->id),
                $data['provider'],
                $actorId,
            );

            return $appointment;
        });
    }

    /**
     * Email the administrators of every UAE organization with no provider
     * appointed, on each configured day before its last date.
     *
     * @return int how many reminders were sent
     */
    public function remind(): int
    {
        $days = array_map('intval', (array) config('peppol.countries.AE.appointment.reminder_days', []));
        $today = CarbonImmutable::today();
        $sent = 0;

        $organizations = Organization::query()->where('country', 'AE')->where('status', 'active')->get();

        foreach ($organizations as $organization) {
            $id = (string) $organization->id;
            $deadline = $this->deadline($id);

            if ($deadline === null || $this->appointed($id)) {
                continue;
            }

            // Signed: a date already past is negative and matches no reminder day.
            $left = (int) $today->diffInDays($deadline, false);

            if (in_array($left, $days, true)) {
                $sent += $this->send($organization, $deadline, $left);
            }
        }

        return $sent;
    }

    /**
     * Its active administrators: who appoints a provider, so who is reminded.
     *
     * @return Collection<int, User>
     */
    public function administrators(Organization $organization): Collection
    {
        return $organization->users()
            ->wherePivotIn('role', MemberRole::ADMINISTERING)
            ->wherePivot('status', 'active')
            ->where('users.status', 'active')
            ->get();
    }

    private function send(Organization $organization, CarbonImmutable $deadline, int $days): int
    {
        $admins = $this->administrators($organization);

        $sent = 0;

        // One each, so each is written in its reader's language.
        foreach ($admins as $admin) {
            try {
                Mail::to($admin)->send(new ProviderAppointmentReminderMail(
                    organizationName: (string) $organization->name,
                    deadline: $deadline->toDateString(),
                    days: $days,
                    url: route('portal.company.edit'),
                ));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}

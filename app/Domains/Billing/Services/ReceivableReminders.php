<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Mail\ReceivableReminderMail;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Reminding customers of the platform's invoices: the configured days
 * before one falls due, and after, while it is unpaid (Finance › Settings,
 * or the customer's own days).
 *
 * Run daily; each reminder day is one calendar day wide, so each is sent
 * once. One email per customer per kind a day, listing every invoice that
 * reached a reminder day, to its owners, administrators and accountants -
 * each in both languages, the reader's first, with a link to the account's
 * statement.
 */
class ReceivableReminders
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly Receivables $receivables,
    ) {}

    /**
     * @return int how many emails were sent
     */
    public function send(?CarbonImmutable $today = null): int
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $due = [];

        foreach (Receivable::withoutTenantScope(fn () => Receivable::query()->whereIn('status', ReceivableStatus::outstandingValues())->get()) as $receivable) {
            $organizationId = (string) $receivable->org_id;
            $days = (int) $today->diffInDays(CarbonImmutable::parse($receivable->due_date->toDateString()), false);

            if ($days > 0 && in_array($days, $this->settings->remindersBefore($organizationId), true)) {
                $due[$organizationId][ReceivableReminderMail::DUE][] = [$receivable, $days];
            } elseif ($days < 0 && in_array(-$days, $this->settings->remindersAfter($organizationId), true)) {
                $due[$organizationId][ReceivableReminderMail::OVERDUE][] = [$receivable, -$days];
            }
        }

        $sent = 0;

        foreach ($due as $organizationId => $kinds) {
            $organization = Organization::query()->find($organizationId);

            if ($organization === null) {
                continue;
            }

            foreach ($kinds as $kind => $rows) {
                $sent += $this->remind($organization, $kind, $rows);
            }
        }

        return $sent;
    }

    /**
     * @param  list<array{0: Receivable, 1: int}>  $rows
     */
    private function remind(Organization $organization, string $kind, array $rows): int
    {
        $numbers = $this->receivables->numbers(array_column($rows, 0));
        $currency = (string) $rows[0][0]->currency;
        $invoices = array_map(fn (array $row): array => [
            'number' => $numbers[(string) $row[0]->id] ?? '',
            'due_date' => $row[0]->due_date->toDateString(),
            'balance' => $row[0]->balance(),
            'currency' => (string) $row[0]->currency,
        ], $rows);

        $recipients = $organization->users()
            ->wherePivotIn('role', [...MemberRole::ADMINISTERING, MemberRole::Accountant->value])
            ->wherePivot('status', 'active')
            ->where('users.status', 'active')
            ->get();

        $sent = 0;

        // One each, so each reads its own language first.
        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(new ReceivableReminderMail(
                    organizationName: (string) $organization->name,
                    kind: $kind,
                    days: max(array_column($rows, 1)),
                    invoices: $invoices,
                    accounts: array_map(fn (array $account): array => [
                        'bank_name' => $account['bank_name'], 'account_name' => $account['account_name'], 'iban' => $account['iban'],
                    ], $this->settings->bankAccounts($currency)),
                    statementUrl: route('portal.billing.statement'),
                ));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}

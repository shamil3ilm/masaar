<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Console;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStartReminders;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\ProviderAppointments;
use Illuminate\Console\Command;

/**
 * Remind UAE organizations with no service provider appointed, on each
 * configured day before their last date to appoint one (UAE Electronic
 * Invoicing Guidelines v1.1, p14, p19), and 90, 30 and 7 days before their
 * own e-invoicing start date, from which every invoice goes through one
 * (6.1; EinvoicingStartReminders). Scheduled daily.
 */
class RemindProviderAppointments extends Command
{
    protected $signature = 'uae:remind-provider-appointments';

    protected $description = 'Email UAE organizations that have not appointed their e-invoicing service provider before the last date or their e-invoicing start date';

    public function handle(ProviderAppointments $appointments, EinvoicingStartReminders $start): int
    {
        $this->info('Sent '.$appointments->remind().' provider appointment reminders.');
        $this->info('Sent '.$start->remind().' e-invoicing start reminders.');

        return self::SUCCESS;
    }
}

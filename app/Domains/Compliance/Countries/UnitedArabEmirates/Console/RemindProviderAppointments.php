<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Console;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\ProviderAppointments;
use Illuminate\Console\Command;

/**
 * Remind UAE organizations with no service provider appointed, on each
 * configured day before their last date to appoint one (UAE Electronic
 * Invoicing Guidelines v1.1, p14, p19). Scheduled daily.
 */
class RemindProviderAppointments extends Command
{
    protected $signature = 'uae:remind-provider-appointments';

    protected $description = 'Email UAE organizations that have not appointed their e-invoicing service provider before the last date';

    public function handle(ProviderAppointments $appointments): int
    {
        $this->info('Sent '.$appointments->remind().' provider appointment reminders.');

        return self::SUCCESS;
    }
}

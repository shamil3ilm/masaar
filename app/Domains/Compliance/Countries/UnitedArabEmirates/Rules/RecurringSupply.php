<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Engine\Contracts\RecurringSupply as RecurringSupplyPort;
use App\Domains\Invoice\Enums\ScheduleFrequency;

/**
 * A UAE invoice for one period of a recurring supply.
 *
 * It is a continuous supply - "a supply that is provided on an
 * ongoing/recurring basis or that includes periodic invoicing" (UAE
 * Electronic Invoicing Guidelines v1.1, p27, scenario 5) - so its
 * transaction type code (BTAE-02) carries the continuous supply flag. Its
 * invoicing period (IBG-14) is the run's supply period, and the period
 * states how often it is billed (BTAE-06, cac:InvoicePeriod/
 * cbc:DescriptionCode) from PINT AE's frequency of billing code list
 * (IBR-005-AE). A school term has no code of its own, so it is Others,
 * and Others is said in words in the invoice note (IBR-160-AE).
 */
class RecurringSupply implements RecurringSupplyPort
{
    /** What a term is called on the document, where the code is Others. */
    public const TERM_NOTE = 'Billed per school term';

    public function periodic(array $payload, ScheduleFrequency $frequency): array
    {
        $flags = str_pad(substr((string) ($payload['transaction_flags'] ?? PintAe::NO_FLAGS), 0, 8), 8, '0');
        $flags[PintAe::FLAG_CONTINUOUS] = '1';
        $code = self::code($frequency);

        return [
            ...$payload,
            'transaction_flags' => $flags,
            'billing_frequency' => $code,
            ...($code === PintAe::BILLING_OTHERS ? ['billing_note' => $payload['billing_note'] ?? self::TERM_NOTE] : []),
        ];
    }

    private static function code(ScheduleFrequency $frequency): string
    {
        return match ($frequency) {
            ScheduleFrequency::Weekly => 'WKY',
            ScheduleFrequency::Monthly => 'MTH',
            ScheduleFrequency::Quarterly => 'QTR',
            ScheduleFrequency::Yearly => 'YRL',
            ScheduleFrequency::Termly => PintAe::BILLING_OTHERS,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Engine\Contracts\RecurringSupply as RecurringSupplyPort;
use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;

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

    /**
     * What the invoice keeps in jurisdiction_data, as the request fields
     * InvoiceRules::countryFields() made it from; a line's own details go
     * back on its line. Its billing frequency is not copied: each run states
     * the schedule's own.
     */
    public function fromInvoice(Invoice $invoice): array
    {
        $data = (array) ($invoice->jurisdiction_data ?? []);
        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $position => $details) {
            $lines[(int) $position - 1] = (array) $details;
        }

        return array_filter([
            ...array_intersect_key($data, array_flip([
                'document_category', 'transaction_flags', 'buyer_endpoint', 'buyer_subdivision', 'buyer_legal_registration',
                'beneficiary_id', 'principal_id', 'delivery_address',
            ])),
            'lines' => $lines,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * The buyer by TRN, with the address PINT AE asks for (IBG-08), the
     * emirate (IBR-128-AE) and the Peppol endpoint the service provider
     * issued (IBT-049), never derived.
     */
    public function buyerFrom(Customer $customer): array
    {
        $address = array_filter([
            'street' => self::text($customer->street),
            'building_number' => self::text($customer->building_number),
            'city' => self::text($customer->city),
            'postal_code' => self::text($customer->postal_code),
            'country_code' => self::text($customer->country_code) ?? 'AE',
        ]);

        return [
            'buyer_name' => self::text($customer->name),
            'buyer_name_ar' => self::text($customer->name_ar),
            'buyer_vat_number' => self::text($customer->vat_number),
            'buyer_address' => $address,
            'buyer_subdivision' => self::text($customer->subdivision),
            'buyer_endpoint' => self::text($customer->peppol_endpoint),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
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

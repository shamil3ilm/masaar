<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\RecurringSupply as RecurringSupplyPort;
use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;

/**
 * A Saudi invoice states a periodic supply by its period alone: the supply
 * date (KSA-5) and the supply end date (KSA-24), which the end date needs
 * beside it and may not precede (BR-KSA-35, BR-KSA-36; XML Implementation
 * Standard v1.2). Every run already carries both, so nothing is added.
 */
class RecurringSupply implements RecurringSupplyPort
{
    public function periodic(array $payload, ScheduleFrequency $frequency): array
    {
        return $payload;
    }

    /** A Saudi invoice keeps everything it says in the shared columns. */
    public function fromInvoice(Invoice $invoice): array
    {
        return [];
    }

    /**
     * The buyer by VAT number or, without one, by an identifier and its
     * register (BT-46, BT-46-1), with the national address; the customer's
     * id too, which names the zero rating a citizen is eligible for
     * (InvoiceRules::defaults).
     */
    public function buyerFrom(Customer $customer): array
    {
        $address = [];

        foreach (Customer::ADDRESS as $key) {
            $address[$key] = filled($customer->{$key}) ? (string) $customer->{$key} : null;
        }

        return [
            'customer_id' => (string) $customer->id,
            'buyer_name' => self::text($customer->name),
            'buyer_name_ar' => self::text($customer->name_ar),
            'buyer_vat_number' => self::text($customer->vat_number),
            'buyer_id' => self::text($customer->buyer_id),
            'buyer_id_scheme' => self::text($customer->buyer_id_scheme),
            'buyer_address' => array_filter($address) === [] ? null : array_filter($address),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }
}

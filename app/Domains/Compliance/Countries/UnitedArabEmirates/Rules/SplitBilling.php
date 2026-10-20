<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Engine\Contracts\SplitBilling as SplitBillingPort;
use App\Domains\Invoice\Enums\InvoiceType;

/**
 * Not offered in the UAE.
 *
 * A split sale is two documents, and the patient's would be a sale to a
 * consumer, which UAE e-invoicing leaves out: "Any supplies to or from
 * natural persons who are not in Business are not within the scope of
 * Electronic Invoicing ... There is no obligation for the supplier or the
 * agent to issue an Electronic Invoice in relation to a supply that is made
 * to a consumer" (UAE Electronic Invoicing Guidelines v1.1, 1 June 2026,
 * §6.2, p14). This platform issues e-invoices only, so it has no document to
 * give the patient. The insurer's share is a business sale like any other,
 * invoiced to the insurer on the ordinary invoice form; PINT AE has no flag
 * for a third party paying (its transaction types, BTAE-02, are free trade
 * zone, deemed supply, margin scheme, summary, continuous supply, agent
 * billing, e-commerce and exports), so nothing more is owed on it.
 */
class SplitBilling implements SplitBillingPort
{
    public function unavailable(): ?string
    {
        return __('In the UAE a sale to a patient is outside e-invoicing (UAE Electronic Invoicing Guidelines, section 6.2), so a sale is not split here: invoice the insurer\'s share to the insurer on the ordinary invoice form.');
    }

    public function patientType(): string
    {
        return InvoiceType::Simplified->value;
    }

    public function insurerType(): string
    {
        return InvoiceType::Standard->value;
    }

    public function insurerLine(array $line): array
    {
        return $line;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\SplitBilling as SplitBillingPort;
use App\Domains\Invoice\Enums\InvoiceType;

/**
 * A Saudi sale an insurer pays for, as ZATCA's General VAT Guideline
 * Healthcare (January 2023) has it.
 *
 * The invoice is issued to the recipient (§5.2, p24). The patient is
 * presumed to be the recipient "even where ... it is the third party who is
 * responsible for paying" (§5.1, p21): a consumer, given a simplified tax
 * invoice - "generally issued for a B2C transaction" (E-Invoicing Detailed
 * Guidelines v2, §2.3 p8) - that states what is for the account of the
 * insurer (§5.2 p24, Example 10 p23). Where the insurer contracted for the
 * care and the patient neither contracts nor pays (Example 11, p23), the
 * insurer is the recipient: a tax invoice to it as a registered buyer, with
 * its VAT number (BT-48, BR-KSA-44) and national address (BR-KSA-63), which
 * it deducts as input VAT (p25), cleared before it is handed over.
 *
 * VAT follows the recipient, line by line. A medicine zero-rated as
 * VATEX-SA-35 is zero-rated as goods "at every stage of the supply chain",
 * "irrespective of how they are used" (§6.1, p29), whoever buys it. A
 * citizen's private healthcare is zero-rated because the State bears its VAT
 * "for Saudi nationals benefiting from private health care services":
 * providers "issue tax invoices to Saudi nationals ... without VAT, after
 * ascertaining the identity of the recipient" and keep their national ID
 * (§5.3.3, p26), which BR-KSA-49 puts on the invoice ("the other buyer ID
 * (BT-46) is mandatory and must be national ID (BT-46-1 = NAT)"). So on the
 * patient's own invoice it is zero-rated whoever pays, the insurer's share
 * included; on an invoice to the insurer, the recipient is the insurer, not
 * a Saudi national, and the line is standard-rated (and deductible by it).
 */
class SplitBilling implements SplitBillingPort
{
    public function unavailable(): ?string
    {
        return null;
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
        if (! in_array($line['exempt_code'] ?? null, FatooraConfig::NAMED_BUYER_EXEMPTIONS, true)) {
            return $line;
        }

        unset($line['exempt_code'], $line['exempt_reason']);

        return [
            ...$line,
            'tax_category' => FatooraConfig::TAX_CATEGORY_STANDARD,
            'tax_rate' => InvoiceRules::STANDARD_RATE,
        ];
    }
}

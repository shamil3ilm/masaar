<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\SplitBilling as SplitBillingPort;
use App\Domains\Invoice\Enums\InvoiceType;

/**
 * A Saudi sale billed to a patient and their insurer.
 *
 * Two recipients pay for one supply, so each is given a document for what it
 * pays. The patient is a consumer: a simplified tax invoice, "generally
 * issued for a B2C transaction" (ZATCA E-Invoicing Detailed Guidelines v2,
 * §2.3, p8), reported within 24 hours. The insurer is a business: a tax
 * invoice to it as a registered buyer, with its VAT number (BT-48,
 * BR-KSA-44) and national address (BR-KSA-63), cleared before it is handed
 * over. A simplified invoice to a business is allowed below SAR 1,000 (p17),
 * but the insurer's share is always a tax invoice: the conservative reading,
 * and what an insurer reclaims its input tax on.
 *
 * VAT follows each share's own consideration, line by line, so a mixed
 * basket keeps its categories in both. A medicine zero-rated as
 * VATEX-SA-35 ("Medicines and medical equipment", XML Implementation
 * Standard v1.2 §11.2.4) is zero-rated as goods, whoever buys it, and stays
 * so in both shares. VATEX-SA-HEA and VATEX-SA-EDU ("Private healthcare to
 * citizen") are zero-rated because of who receives the supply, and BR-KSA-49
 * ties them to the buyer: "the other buyer ID (BT-46) is mandatory and must
 * be national ID (BT-46-1 = NAT)". On the insurer's invoice the buyer is the
 * insurer, which has no national ID, and naming the patient's there would
 * misstate the buyer; the authority said at the outset that "for citizens
 * that have insurance coverage, insurers will bear the levy" (GAZT, 8 January
 * 2018). So the insurer's share of such a line is standard-rated, and the
 * patient's keeps the zero rating with the national ID it needs.
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

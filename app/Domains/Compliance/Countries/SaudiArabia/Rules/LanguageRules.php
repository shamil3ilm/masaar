<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\LanguageRules as LanguageRulesPort;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * A Saudi invoice is in Arabic: ZATCA's E-Invoicing Detailed Guidelines
 * (v2, May 2023) p60 and p63, as ArabicText sets out. A draft without it is
 * refused by InvoiceRules, and a seller without an Arabic name by
 * SellerRules; this answers the screens that show what was let through
 * under a grace period.
 */
class LanguageRules implements LanguageRulesPort
{
    public function requiresArabic(): bool
    {
        return true;
    }

    /** Required, in Arabic letters: a line or an invoice takes it as its own. */
    public function arabicNameRules(string $of): array
    {
        return [
            new ArabicName($of === self::PRODUCT
                ? __('Arabic product name required (ZATCA: the invoice must be in Arabic)', [], 'en')
                : __('Arabic customer name required (ZATCA: the invoice must be in Arabic)', [], 'en')),
            'string',
            'max:255',
        ];
    }

    /** The draft's own text, and the seller's Arabic name, which the document carries as its seller. */
    public function missingInDraft(array $draft, Organization $seller): array
    {
        $missing = ArabicText::missing($draft);

        if (! ArabicText::isArabic($seller->name_ar)) {
            $missing = ['seller.name_ar' => ArabicText::message(__('Arabic seller name required (ZATCA: the invoice must be in Arabic)', [], 'en')), ...$missing];
        }

        return $missing;
    }

    public function missingText(Invoice $invoice): array
    {
        return ArabicText::missing([
            'type' => $invoice->type,
            'notes' => $invoice->notes,
            'buyer_name_ar' => $invoice->buyer_name_ar,
            'lines' => $invoice->lines->map(fn ($line): array => ['description_ar' => $line->description_ar])->all(),
        ]);
    }
}

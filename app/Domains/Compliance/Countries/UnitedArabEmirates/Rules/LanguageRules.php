<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Engine\Contracts\LanguageRules as LanguageRulesPort;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * A UAE invoice may be in English alone. The Electronic Invoicing
 * Guidelines (v1.1) §5.1, p11: the framework "is designed to support both
 * the Arabic and English languages", and the sample invoice on p34 is in
 * English only. The Arabic names an organization keeps are printed when
 * given, and never required.
 */
class LanguageRules implements LanguageRulesPort
{
    public function requiresArabic(): bool
    {
        return false;
    }

    /**
     * English alone, as the human-readable sample tax invoice in the
     * Electronic Invoicing Guidelines (v1.1, 12.1, p34) is, or both
     * languages, which the framework supports (5.1, p11). English unless
     * the organization chooses both.
     */
    public function printLanguages(): array
    {
        return [self::ENGLISH, self::ENGLISH_FIRST, self::ARABIC_FIRST];
    }

    public function arabicNameRules(string $of): array
    {
        return ['nullable', 'string', 'max:255'];
    }

    public function arabicTextRules(): array
    {
        return ['nullable', 'string'];
    }

    public function missingText(Invoice $invoice): array
    {
        return [];
    }

    public function missingInDraft(array $draft, Organization $seller): array
    {
        return [];
    }
}

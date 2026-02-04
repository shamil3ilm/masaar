<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\Language;
use App\Models\Core\OrganizationBranding;
use App\Models\Core\Translation;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

class LocalizationService
{
    protected ?string $currentLanguage = null;
    protected ?int $organizationId = null;

    /**
     * Set the current language.
     */
    public function setLanguage(string $languageCode): void
    {
        $this->currentLanguage = $languageCode;
        App::setLocale($languageCode);
    }

    /**
     * Get the current language code.
     */
    public function getLanguage(): string
    {
        return $this->currentLanguage ?? App::getLocale() ?? 'en';
    }

    /**
     * Set the organization context.
     */
    public function setOrganization(int $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Get a translation.
     */
    public function translate(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->getLanguage();

        // First try custom translation
        $translation = Translation::get($key, $locale, $this->organizationId);

        // Fall back to Laravel's translation
        if ($translation === null) {
            $translation = __($key, $replace, $locale);

            // If Laravel returns the key, it means no translation found
            if ($translation === $key) {
                // Try to get a more user-friendly version
                $translation = $this->humanize($key);
            }
        } else {
            // Replace placeholders in custom translation
            foreach ($replace as $placeholder => $value) {
                $translation = str_replace(":{$placeholder}", $value, $translation);
            }
        }

        return $translation;
    }

    /**
     * Alias for translate.
     */
    public function t(string $key, array $replace = [], ?string $locale = null): string
    {
        return $this->translate($key, $replace, $locale);
    }

    /**
     * Get all translations for current language.
     */
    public function getAllTranslations(?string $locale = null): array
    {
        $locale = $locale ?? $this->getLanguage();

        return Translation::getAllForLanguage($locale, $this->organizationId);
    }

    /**
     * Get translations for a specific group.
     */
    public function getGroupTranslations(string $group, ?string $locale = null): array
    {
        $locale = $locale ?? $this->getLanguage();

        return Translation::getGroup($group, $locale, $this->organizationId);
    }

    /**
     * Check if current language is RTL.
     */
    public function isRtl(?string $locale = null): bool
    {
        $locale = $locale ?? $this->getLanguage();
        $language = Language::getByCode($locale);

        return $language?->isRtl() ?? in_array($locale, ['ar', 'ar_ae', 'ur', 'he', 'fa']);
    }

    /**
     * Get text direction.
     */
    public function getDirection(?string $locale = null): string
    {
        return $this->isRtl($locale) ? 'rtl' : 'ltr';
    }

    /**
     * Get all active languages.
     */
    public function getActiveLanguages(): array
    {
        return Language::getActiveLanguages();
    }

    /**
     * Get language by code.
     */
    public function getLanguageInfo(string $code): ?Language
    {
        return Language::getByCode($code);
    }

    /**
     * Format number according to locale.
     */
    public function formatNumber(
        float|int|string $number,
        int $decimals = 2,
        ?string $locale = null
    ): string {
        $locale = $locale ?? $this->getLanguage();

        // Use Arabic-Indic numerals for Arabic
        if (in_array($locale, ['ar', 'ar_ae'])) {
            $formatted = number_format((float)$number, $decimals, '٫', '٬');
            return $this->toArabicNumerals($formatted);
        }

        // Use Devanagari numerals for Hindi (optional)
        // For now, use standard numerals for Hindi

        return number_format((float)$number, $decimals, '.', ',');
    }

    /**
     * Format currency according to locale.
     */
    public function formatCurrency(
        float|int|string $amount,
        string $currencyCode,
        ?string $locale = null
    ): string {
        $locale = $locale ?? $this->getLanguage();
        $formatted = $this->formatNumber($amount, 2, $locale);

        // Currency symbol placement
        $currencySymbols = [
            'SAR' => 'ر.س',
            'AED' => 'د.إ',
            'QAR' => 'ر.ق',
            'OMR' => 'ر.ع',
            'BHD' => 'د.ب',
            'KWD' => 'د.ك',
            'INR' => '₹',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
        ];

        $symbol = $currencySymbols[$currencyCode] ?? $currencyCode;

        // RTL languages typically put currency symbol after
        if ($this->isRtl($locale)) {
            return "{$formatted} {$symbol}";
        }

        // For INR and Western currencies, symbol comes first
        if (in_array($currencyCode, ['INR', 'USD', 'EUR', 'GBP'])) {
            return "{$symbol}{$formatted}";
        }

        return "{$formatted} {$symbol}";
    }

    /**
     * Format date according to locale.
     */
    public function formatDate(
        \DateTimeInterface|string $date,
        string $format = 'medium',
        ?string $locale = null
    ): string {
        $locale = $locale ?? $this->getLanguage();

        if (is_string($date)) {
            $date = new \DateTime($date);
        }

        $formats = [
            'short' => 'd/m/Y',
            'medium' => 'd M Y',
            'long' => 'd F Y',
            'full' => 'l, d F Y',
        ];

        $dateFormat = $formats[$format] ?? $format;

        // For Arabic, we might want to use Hijri calendar (optional)
        // For now, use Gregorian with Arabic month names

        return $date->format($dateFormat);
    }

    /**
     * Get organization branding.
     */
    public function getBranding(?int $organizationId = null): OrganizationBranding
    {
        $orgId = $organizationId ?? $this->organizationId;

        if (!$orgId) {
            return new OrganizationBranding(OrganizationBranding::DEFAULT_COLORS);
        }

        return OrganizationBranding::getForOrganization($orgId);
    }

    /**
     * Get CSS variables for branding.
     */
    public function getBrandingCss(?int $organizationId = null): string
    {
        return $this->getBranding($organizationId)->generateCssString();
    }

    /**
     * Convert Western numerals to Arabic-Indic.
     */
    protected function toArabicNumerals(string $number): string
    {
        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

        return str_replace($western, $arabic, $number);
    }

    /**
     * Convert key to human-readable format.
     */
    protected function humanize(string $key): string
    {
        $parts = explode('.', $key);
        $last = end($parts);

        return ucfirst(str_replace(['_', '-'], ' ', $last));
    }

    /**
     * Get localization data for frontend.
     */
    public function getFrontendData(): array
    {
        $language = $this->getLanguage();

        return [
            'language' => $language,
            'direction' => $this->getDirection(),
            'is_rtl' => $this->isRtl(),
            'languages' => $this->getActiveLanguages(),
            'translations' => $this->getAllTranslations(),
            'branding' => $this->getBranding()->toArray(),
            'css_variables' => $this->getBranding()->getCssVariables(),
        ];
    }
}

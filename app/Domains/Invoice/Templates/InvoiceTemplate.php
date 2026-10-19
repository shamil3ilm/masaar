<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Templates;

use App\Domains\Compliance\Engine\DTOs\PrintLabel;

/**
 * How an organization's invoices look on paper, resolved: its layout, its
 * colour, its logo, the text it adds and the optional columns it shows.
 *
 * What the template may not change is the country's: every field the
 * country requires on a printed copy is printed whatever the template says
 * (Presentation::printedFields). A template restyles and moves those; it
 * adds its own text around them.
 *
 * Built by InvoiceTemplates from the stored values - the platform's, the
 * organization's and the branch's, laid over one another - or from a form's
 * values for a preview.
 */
final readonly class InvoiceTemplate
{
    /** The stored fields, each level keeping those it changes. */
    public const FIELDS = [
        'layout', 'accent', 'logo_position', 'logo_size',
        'header_en', 'header_ar', 'footer_en', 'footer_ar', 'terms_en', 'terms_ar',
        'bank_name', 'bank_iban', 'bank_account_name', 'bank_swift',
        'column_unit', 'column_discount', 'column_vat_rate', 'column_item_code',
        'business_qr', 'language',
    ];

    /**
     * As shipped: today's classic page. The accent is the organization's
     * theme colour and the language its printing order, until a level sets
     * its own.
     */
    public const DEFAULTS = [
        'layout' => 'classic',
        'accent' => null,
        'logo_position' => 'start',
        'logo_size' => 'medium',
        'header_en' => '', 'header_ar' => '',
        'footer_en' => '', 'footer_ar' => '',
        'terms_en' => '', 'terms_ar' => '',
        'bank_name' => '', 'bank_iban' => '', 'bank_account_name' => '', 'bank_swift' => '',
        'column_unit' => true,
        'column_discount' => false,
        'column_vat_rate' => false,
        'column_item_code' => false,
        'business_qr' => true,
        'language' => null,
    ];

    /** Where the logo sits: the leading language's side, or a fixed one. */
    public const LOGO_POSITIONS = ['start', 'left', 'center', 'right'];

    /** The logo's height, in millimetres, by size. */
    public const LOGO_SIZES = ['small' => 10.0, 'medium' => 14.0, 'large' => 20.0];

    /** Bilingual, either language first, or one language alone. */
    public const LANGUAGES = ['arabic_first', 'english_first', 'arabic', 'english'];

    /** The columns a template may add to the lines. */
    public const COLUMNS = ['unit', 'discount', 'vat_rate', 'item_code'];

    /** Whether the Arabic leads, right to left. */
    public bool $arabicFirst;

    /**
     * @param  array<string, bool>  $columns  by COLUMNS
     * @param  array<string, mixed>  $values  the resolved stored values, for a form
     */
    public function __construct(
        public TemplateLayout $layout,
        public string $accent,
        public string $logoPosition,
        public string $logoSize,
        public PrintLabel $header,
        public PrintLabel $footer,
        public PrintLabel $terms,
        public BankDetails $bank,
        public array $columns,
        public bool $businessQr,
        public string $language,
        public array $values = [],
    ) {
        $this->arabicFirst = $language === 'arabic_first' || $language === 'arabic';
    }

    /** Whether both languages are printed. */
    public function bilingual(): bool
    {
        return in_array($this->language, ['arabic_first', 'english_first'], true);
    }

    /** The logo's height, in millimetres. */
    public function logoMm(): float
    {
        return self::LOGO_SIZES[$this->logoSize] ?? self::LOGO_SIZES['medium'];
    }

    /**
     * The logo's side on a page whose leading side is $start ('left' or
     * 'right'): left, center or right.
     */
    public function logoSide(string $start): string
    {
        return $this->logoPosition === 'start' ? $start : $this->logoPosition;
    }

    /**
     * @param  array<string, mixed>  $values  every FIELD, resolved
     * @param  string  $accent  the colour it prints in, already chosen
     * @param  string  $language  one of LANGUAGES, already allowed for the country
     */
    public static function fromValues(array $values, string $accent, string $language): self
    {
        $values = array_replace(self::DEFAULTS, array_intersect_key($values, self::DEFAULTS));
        $text = fn (string $field): string => trim((string) ($values[$field] ?? ''));

        return new self(
            layout: TemplateLayout::tryFrom((string) $values['layout']) ?? TemplateLayout::Classic,
            accent: $accent,
            logoPosition: in_array($values['logo_position'], self::LOGO_POSITIONS, true) ? $values['logo_position'] : 'start',
            logoSize: array_key_exists((string) $values['logo_size'], self::LOGO_SIZES) ? (string) $values['logo_size'] : 'medium',
            header: new PrintLabel($text('header_en'), $text('header_ar')),
            footer: new PrintLabel($text('footer_en'), $text('footer_ar')),
            terms: new PrintLabel($text('terms_en'), $text('terms_ar')),
            bank: new BankDetails($text('bank_name'), Iban::normalise($text('bank_iban')), $text('bank_account_name'), strtoupper($text('bank_swift'))),
            columns: array_combine(self::COLUMNS, array_map(fn (string $column): bool => (bool) $values['column_'.$column], self::COLUMNS)),
            businessQr: (bool) $values['business_qr'],
            language: $language,
            values: $values,
        );
    }
}

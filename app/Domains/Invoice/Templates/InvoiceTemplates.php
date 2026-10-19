<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Templates;

use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Engine\Contracts\Presentation;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every invoice template: the platform's default, each organization's over
 * it, and a branch's over its organization's.
 *
 * One customization (KEY), a record whose levels each keep only the fields
 * they change, so a field an organization left alone follows the platform's
 * when that changes. Each change is recorded as any customization is, with
 * who made it and the fields before and after; a reset goes back to what the
 * level above says.
 *
 * Where a template leaves a choice to the organization it is filled in on
 * reading: the colour from the organization's theme, the language from its
 * printing order (PrintPreferences). A language its country does not allow -
 * English alone where an invoice must be in Arabic - prints in both
 * languages instead, that one first.
 */
class InvoiceTemplates
{
    public const KEY = 'invoices.template';

    private const TEXT = ['header_en', 'header_ar', 'footer_en', 'footer_ar', 'terms_en', 'terms_ar', 'bank_name', 'bank_account_name'];

    private const FLAGS = ['column_unit', 'column_discount', 'column_vat_rate', 'column_item_code', 'business_qr'];

    public function __construct(
        private readonly Customizations $customizations,
        private readonly ThemeService $themes,
        private readonly PrintPreferences $preferences,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly BranchService $branches,
    ) {}

    /**
     * The template the organization's invoices print with: the branch's when
     * the invoice is one of its branch's.
     */
    public function for(Organization $organization, ?string $branchId = null): InvoiceTemplate
    {
        return $this->build($this->values($this->scope($organization, $branchId)), $organization);
    }

    /**
     * What a country's printed layouts are given besides the invoice: the
     * template (the invoice's branch's unless one is given - a preview's),
     * the columns its lines print, which the country can lock on
     * (PrintedColumns), and each line's item code.
     *
     * @return array{template: InvoiceTemplate, columns: array<string, bool>, itemCodes: array<string, string>}
     */
    public function printing(Invoice $invoice, Organization $organization, Presentation $country, ?InvoiceTemplate $template = null): array
    {
        $template ??= $this->for($organization, $invoice->branch_id);

        return [
            'template' => $template,
            'columns' => PrintedColumns::for($template, $country->printedFields(), $invoice),
            'itemCodes' => $template->columns['item_code'] ? PrintedColumns::itemCodes($invoice) : [],
        ];
    }

    /** The platform's own, as an organization that changed nothing prints it. */
    public function platform(): InvoiceTemplate
    {
        return $this->build($this->values(Scope::platform()), null);
    }

    /**
     * A template from stored or submitted values, for the organization
     * whose invoice it lays out; with none, the platform's.
     *
     * @param  array<string, mixed>  $values
     */
    public function build(array $values, ?Organization $organization): InvoiceTemplate
    {
        $accent = is_string($values['accent'] ?? null) && preg_match('/\A#[0-9A-Fa-f]{6}\z/', $values['accent']) === 1
            ? strtoupper($values['accent'])
            : $this->themeAccent($organization);

        // Legible on paper and in black and white whatever was stored: a
        // theme's colour was chosen for screens (Contrast).
        return InvoiceTemplate::fromValues($values, Contrast::legibleShade($accent), $this->language($values['language'] ?? null, $organization));
    }

    /**
     * Every field as it stands for the scope, the levels above included.
     *
     * @return array<string, mixed>
     */
    public function values(Scope $scope): array
    {
        return array_replace(InvoiceTemplate::DEFAULTS, (array) $this->customizations->get(self::KEY, $scope));
    }

    /**
     * The fields the level itself changes; empty when it follows the level
     * above in everything.
     *
     * @return array<string, mixed>
     */
    public function own(Level $level, Scope $scope): array
    {
        return (array) ($this->customizations->own(self::KEY, $level, $scope) ?? []);
    }

    /**
     * What the level would have without its own: the level above's.
     *
     * @return array<string, mixed>
     */
    public function inherited(Level $level, Scope $scope): array
    {
        return match ($level) {
            Level::Platform => InvoiceTemplate::DEFAULTS,
            Level::Organization => $this->values(Scope::platform()),
            default => $this->values(Scope::organization((string) $scope->organizationId)),
        };
    }

    /**
     * Change a level's template: the fields given, each checked, kept only
     * where they differ from the level above's. Fields not given keep what
     * the level had.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when a field is not one the template can hold
     */
    public function save(Level $level, Scope $scope, array $input, ?Organization $organization, ?string $actorId): void
    {
        $this->assertBranch($level, $scope);

        $inherited = $this->inherited($level, $scope);
        $own = $this->own($level, $scope);

        foreach ($this->validate($input, $organization) as $field => $value) {
            if ($value === $inherited[$field]) {
                unset($own[$field]);
            } else {
                $own[$field] = $value;
            }
        }

        $own === []
            ? $this->customizations->reset(self::KEY, $level, $scope, $actorId)
            : $this->customizations->set(self::KEY, $level, $scope, $own, $actorId);
    }

    /**
     * A template from a form not yet saved, for its preview: the scope's
     * template with the form's fields laid over it, each taken only when it
     * is one the template could hold - a half-typed IBAN previews as the
     * saved one, not as an error.
     *
     * @param  array<string, mixed>  $input
     */
    public function preview(Scope $scope, array $input, ?Organization $organization): InvoiceTemplate
    {
        $values = $this->values($scope);

        foreach (array_intersect_key($input, InvoiceTemplate::DEFAULTS) as $field => $value) {
            try {
                $values = [...$values, ...$this->validate([$field => $value], $organization)];
            } catch (ValidationException) {
                continue;
            }
        }

        return $this->build($values, $organization);
    }

    /** Back to the level above's template, every field. */
    public function reset(Level $level, Scope $scope, ?string $actorId): void
    {
        $this->assertBranch($level, $scope);

        $this->customizations->reset(self::KEY, $level, $scope, $actorId);
    }

    /**
     * The languages the organization's invoices may print in, as its
     * country allows (LanguageRules::printLanguages). The platform's default
     * may be any of them, and is read by each country as it allows.
     *
     * @return array<string, string> value => label
     */
    public function languages(?Organization $organization): array
    {
        $labels = [
            'arabic_first' => __('Arabic and English, Arabic first'),
            'english_first' => __('English and Arabic, English first'),
            'arabic' => __('Arabic only'),
            'english' => __('English only'),
        ];

        if ($organization === null) {
            return $labels;
        }

        $allowed = $this->jurisdictions->forRules($organization)->languageRules()->printLanguages();

        // In the country's order: its first is its default.
        return array_combine($allowed, array_map(fn (string $language): string => $labels[$language], $allowed));
    }

    /**
     * The organization's branches, when it is one of them; another
     * organization's branch is not a level it can set.
     *
     * @throws ValidationException
     */
    private function assertBranch(Level $level, Scope $scope): void
    {
        if ($level !== Level::Branch) {
            return;
        }

        if (! $this->branches->belongsTo((string) $scope->branchId, (string) $scope->organizationId)) {
            throw ValidationException::withMessages(['branch' => __('That branch is not one of this organization\'s.')]);
        }
    }

    /**
     * The given fields, checked and in the form they are kept in.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $input, ?Organization $organization): array
    {
        $input = array_intersect_key($input, InvoiceTemplate::DEFAULTS);
        $arabic = $organization === null ? ['nullable', 'string'] : $this->jurisdictions->forRules($organization)->languageRules()->arabicTextRules();

        $valid = Validator::make($input, [
            'layout' => ['sometimes', Rule::enum(TemplateLayout::class)],
            'accent' => ['nullable', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && preg_match('/\A#[0-9A-Fa-f]{6}\z/', $value) === 1 && ! Contrast::legible($value)) {
                    $fail(__('This colour is too light to read on paper or to print in black and white: choose a darker one, with contrast against white of at least 4.5 to 1.'));
                }
            }],
            'logo_position' => ['sometimes', Rule::in(InvoiceTemplate::LOGO_POSITIONS)],
            'logo_size' => ['sometimes', Rule::in(array_keys(InvoiceTemplate::LOGO_SIZES))],
            'header_en' => ['nullable', 'string', 'max:300'],
            'footer_en' => ['nullable', 'string', 'max:300'],
            'terms_en' => ['nullable', 'string', 'max:2000'],
            'header_ar' => [...$arabic, 'max:300'],
            'footer_ar' => [...$arabic, 'max:300'],
            'terms_ar' => [...$arabic, 'max:2000'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:140'],
            'bank_iban' => ['nullable', 'string', 'max:50', new Iban],
            // ISO 9362: bank, country, location, and an optional branch.
            'bank_swift' => ['nullable', 'string', 'regex:/\A[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?\z/'],
            'column_unit' => ['sometimes', 'boolean'],
            'column_discount' => ['sometimes', 'boolean'],
            'column_vat_rate' => ['sometimes', 'boolean'],
            'column_item_code' => ['sometimes', 'boolean'],
            'business_qr' => ['sometimes', 'boolean'],
            'language' => ['nullable', Rule::in(array_keys($this->languages($organization)))],
        ], [], $this->attributes())->validate();

        $clean = [];

        foreach ($valid as $field => $value) {
            $clean[$field] = match (true) {
                in_array($field, self::FLAGS, true) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                in_array($field, self::TEXT, true) => trim((string) $value),
                $field === 'bank_iban' => Iban::normalise((string) $value),
                $field === 'bank_swift' => strtoupper(trim((string) $value)),
                $field === 'accent' => $value === null || $value === '' ? null : strtoupper((string) $value),
                $field === 'language' => $value === null || $value === '' ? null : (string) $value,
                default => (string) $value,
            };
        }

        return $clean;
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'layout' => __('Layout'), 'accent' => __('Colour'), 'logo_position' => __('Logo position'), 'logo_size' => __('Logo size'),
            'header_en' => __('Header (English)'), 'header_ar' => __('Header (Arabic)'),
            'footer_en' => __('Footer (English)'), 'footer_ar' => __('Footer (Arabic)'),
            'terms_en' => __('Terms and conditions (English)'), 'terms_ar' => __('Terms and conditions (Arabic)'),
            'bank_name' => __('Bank'), 'bank_iban' => __('IBAN'), 'bank_account_name' => __('Account name'), 'bank_swift' => __('SWIFT code'),
            'language' => __('Language'),
        ];
    }

    /**
     * The language it prints in: the template's when the country allows it,
     * else both with the chosen one first; with none, the organization's
     * printing order.
     */
    private function language(mixed $chosen, ?Organization $organization): string
    {
        $allowed = array_keys($this->languages($organization));

        if (is_string($chosen) && in_array($chosen, $allowed, true)) {
            return $chosen;
        }

        if (is_string($chosen) && in_array($chosen, InvoiceTemplate::LANGUAGES, true)) {
            // One language alone where the country prints both: both, that
            // one first; Arabic alone where it is not offered: both, Arabic
            // first.
            return $chosen === 'english' ? 'english_first' : 'arabic_first';
        }

        // None chosen: the country's own default, or - where the country
        // has the organization choose its order - that order.
        $country = $organization === null ? null : $this->jurisdictions->forRules($organization);

        if ($country === null || $country->presentation()->usesCustomization('printing.language_order')) {
            return $organization === null || $this->preferences->arabicFirst($organization) ? 'arabic_first' : 'english_first';
        }

        return $allowed[0];
    }

    /** The organization's theme colour, or the platform's. */
    private function themeAccent(?Organization $organization): string
    {
        $theme = $this->themes->forScope($organization === null ? ThemeService::PLATFORM : (string) $organization->id);

        return strtoupper((string) ($theme->definition->colors['primary'] ?? ThemeDefinition::shipped()->colors['primary']));
    }

    private function scope(Organization $organization, ?string $branchId): Scope
    {
        $organizationId = (string) $organization->id;

        return $branchId !== null && $this->branches->belongsTo($branchId, $organizationId) ? Scope::branch($organizationId, (string) $branchId) : Scope::organization($organizationId);
    }
}

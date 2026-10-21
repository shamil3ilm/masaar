<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use App\Domains\Compliance\Engine\Contracts\LanguageRules;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\VatNumber;
use App\Support\CountryNames;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What a saved customer and a saved product have to be, for the
 * organization's country: one set of rules for the portal's forms and the
 * CSV import, so a row the import accepts is one the form would.
 */
final class CatalogueRules
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * The customer's rules and their messages. The country's buyer rules
     * replace a shared one in its place, and add their own after.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function customer(?Organization $organization, Request $request, ?Customer $customer = null): array
    {
        $jurisdiction = $this->jurisdictions->forRules($organization);
        $rules = $jurisdiction->invoiceRules();
        $language = $jurisdiction->languageRules();

        // An insurer is invoiced for its share of a split sale on a tax
        // invoice to a registered buyer, so it keeps the VAT number and the
        // address that invoice names it by.
        $required = $request->boolean('is_insurer') ? ['required'] : ['nullable'];

        return [[...[
            'name' => ['required', 'string', 'max:255'],
            // What an invoice naming the customer takes as the buyer's
            // Arabic name, required where the country requires Arabic.
            'name_ar' => $language->arabicNameRules(LanguageRules::CUSTOMER),
            'vat_number' => [
                ...$required, 'string', new VatNumber,
                Rule::unique('customers', 'vat_number')->where('org_id', $organization?->id)->withoutTrashed()->ignore($customer?->id),
            ],
            'street' => [...$required, 'string', 'max:255'],
            'building_number' => [...$required, 'string', 'max:20'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => [...$required, 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            // A code from the list the form offers (ISO 3166-1 alpha-2).
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/', Rule::in(CountryNames::CODES)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Printed on the invoices that take them: in Arabic where the
            // country requires it.
            'payment_terms' => [...$language->arabicTextRules(), 'max:1000'],
        ], ...$rules->buyerRules($request)], [
            'vat_number.unique' => __('Another customer already has this VAT number.'),
            'vat_number.required' => __('An insurer is invoiced as a registered buyer: give its VAT number.'),
            ...$rules->buyerMessages(),
        ]];
    }

    /**
     * The product's rules and their messages.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function product(?Organization $organization, Request $request, ?Product $product = null): array
    {
        $jurisdiction = $this->jurisdictions->forRules($organization);

        return [[
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => $jurisdiction->languageRules()->arabicNameRules(LanguageRules::PRODUCT),
            'sku' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'sku')->where('org_id', $organization?->id)->withoutTrashed()->ignore($product?->id),
            ],
            'unit_code' => ['required', 'string', Rule::in(UnitCode::accepted())],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            // The treatment its lines start in, from the country's code lists.
            ...VatTreatmentChoice::rules($jurisdiction->vatTreatments(), $request),
        ], [
            'sku.unique' => __('Another product already has this code.'),
            ...VatTreatmentChoice::messages(),
        ]];
    }

    /**
     * A validated product as it is kept (VatTreatmentChoice::kept).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function keptProduct(?Organization $organization, array $validated): array
    {
        return VatTreatmentChoice::kept($this->jurisdictions->forRules($organization)->vatTreatments(), $validated);
    }
}

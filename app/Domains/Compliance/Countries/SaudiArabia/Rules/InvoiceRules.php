<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\InvoiceRules as InvoiceRulesPort;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Enums\TaxCategory;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\PrepaymentSettlement;
use App\Domains\Invoice\Support\ChargeRules;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\VatNumber;
use App\Domains\Organization\Services\LanguageGrace;
use App\Domains\Organization\Services\TenantResolver;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A Saudi invoice draft, in the shapes ZATCA checks - before it is drafted,
 * not after it is issued, numbered and written into the chain, when the
 * remedy for a rejection is a credit note.
 *
 * The API, the ERP pipeline and the portal's form all draft by these rules.
 * The validated request is the draft as it is; the transaction types of
 * KSA-2 are the one set of columns only a Saudi invoice carries, and go
 * through countryFields().
 */
class InvoiceRules implements InvoiceRulesPort
{
    /** The standard VAT rate, a line's when it states none. */
    private const STANDARD_RATE = '15';

    /**
     * BT-46-1, the registers a buyer identifier can come from.
     *
     * From the same Schematron that carries the exemption codes. NAT is the
     * one BR-KSA-49 insists on for healthcare and education supplies.
     */
    private const VALID_BUYER_ID_SCHEMES = [
        'TIN',   // Tax identification number
        'CRN',   // Commercial registration number
        'MOM',   // MOMRAH licence
        'MLS',   // MHRSD licence
        '700',   // 700 number
        'SAG',   // MISA licence
        'NAT',   // National ID
        'GCC',   // GCC identifier
        'IQA',   // Iqama
        'PAS',   // Passport
        'OTH',   // Other
    ];

    /**
     * KSA-2 positions 3 to 7, the transaction types an invoice can also be,
     * as the invoice's own columns name them, and position 9, a
     * business-to-government transaction, which ZATCA's SDK defines beside
     * them (BR-KSA-06: "G (position 9) = B2G invoice transaction"). Each is a
     * bit of the type name that is signed
     * (InvoiceXmlData::getInvoiceTypeName()).
     */
    public const TRANSACTION_FLAGS = ['is_third_party', 'is_nominal', 'is_export', 'is_summary', 'is_self_billed', 'is_government'];

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly PrepaymentSettlement $settlement,
        private readonly Catalogue $catalogue,
        private readonly LanguageGrace $grace,
    ) {}

    /**
     * Fill in what the caller left out, without contradicting what they
     * said. A line carrying an exemption code is not standard-rated, so
     * defaulting every line to S at 15% produced documents that claimed
     * both - which ZATCA rejects rather than reconciles. The code decides
     * the category, and an exempt line is not taxed.
     */
    public function defaults(Request $request): array
    {
        $defaults = [
            'document_type' => $request->input('document_type') ?? 'invoice',
            // Upper case, as ISO 4217 and the document have it. 'sar' was
            // stored as typed, slipped past the foreign-currency rule and
            // went out as 'sar', which the authority refuses.
            'currency' => is_string($request->input('currency')) ? strtoupper($request->input('currency')) : FatooraConfig::CURRENCY_SAR,
            'payment_means_code' => $request->input('payment_means_code') ?? '10',
        ];

        if ($request->has('lines')) {
            $defaults['lines'] = collect($request->input('lines'))->map(function ($line) {
                $exemptionCode = $line['exempt_code'] ?? null;

                $category = $line['tax_category']
                    ?? FatooraConfig::taxCategoryFor($exemptionCode, (float) ($line['tax_rate'] ?? self::STANDARD_RATE));

                return $this->productArabic(array_merge([
                    'unit_code' => UnitCode::DEFAULT,
                    'tax_category' => $category,
                    'tax_rate' => $category === FatooraConfig::TAX_CATEGORY_STANDARD ? (int) self::STANDARD_RATE : 0,
                ], $line));
            })->toArray();
        }

        // A saved customer's Arabic name, when the invoice names the
        // customer and not the Arabic (ArabicText).
        if (blank($request->input('buyer_name_ar')) && is_string($request->input('customer_id'))) {
            $arabic = $this->organizationId() === null ? null
                : $this->catalogue->customerArabicName($this->organizationId(), $request->input('customer_id'));

            if ($arabic !== null) {
                $defaults['buyer_name_ar'] = $arabic;
            }
        }

        // A document charge is made at the standard rate unless it says
        // otherwise; one in another category is not taxed.
        if (is_array($request->input('charges'))) {
            $defaults['charges'] = array_map(function ($charge) {
                if (! is_array($charge)) {
                    return $charge;
                }

                $category = $charge['tax_category'] ?? FatooraConfig::TAX_CATEGORY_STANDARD;

                return [
                    'tax_category' => $category,
                    'tax_rate' => $category === FatooraConfig::TAX_CATEGORY_STANDARD ? (int) self::STANDARD_RATE : 0,
                    ...$charge,
                ];
            }, $request->input('charges'));
        }

        return $defaults;
    }

    /**
     * A line naming a saved product, by its id or its code, takes the
     * product's Arabic name when it gives none of its own. Never a machine
     * translation: only what the organization wrote down for the product.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function productArabic(array $line): array
    {
        if (filled($line['description_ar'] ?? null) || $this->organizationId() === null) {
            return $line;
        }

        $arabic = $this->catalogue->productArabicName(
            $this->organizationId(),
            is_string($line['product_id'] ?? null) ? $line['product_id'] : null,
            is_string($line['product_code'] ?? null) ? $line['product_code'] : null,
        );

        return $arabic === null ? $line : [...$line, 'description_ar' => $arabic];
    }

    private function organizationId(): ?string
    {
        return $this->tenant->getOrganizationId();
    }

    public function rules(Request $request): array
    {
        $isB2B = fn () => $request->input('type') === 'standard';

        // BR-KSA-67 applies only to a Saudi buyer. A postal code
        // elsewhere is not five digits, and most are not digits at all.
        $buyerIsSaudi = ($request->input('buyer_address.country_code') ?? 'SA') === 'SA';

        // BR-KSA-25: a simplified invoice names its buyer only when a line is
        // exempt as private education or healthcare to a Saudi citizen. A
        // walk-in sale names nobody, and ZATCA's own sample does not either.
        $mustNameBuyer = fn (): bool => $isB2B() || collect($request->input('lines', []))
            ->contains(fn ($line): bool => in_array(
                $line['exempt_code'] ?? null,
                FatooraConfig::NAMED_BUYER_EXEMPTIONS,
                true,
            ));

        // BR-KSA-81: a standard invoice identifies its buyer by VAT number or,
        // failing that, by another identifier (BT-46). Requiring the VAT
        // number outright refused every business buyer that is not
        // VAT-registered, which the authority accepts.
        $hasBuyerId = fn (): bool => $request->filled('buyer_id');
        $hasBuyerVat = fn (): bool => $request->filled('buyer_vat_number');

        $isNote = fn (): bool => in_array($request->input('document_type'), ['credit_note', 'debit_note']);

        return [
            // Invoice header. Left out, the invoice is numbered when it is
            // issued, from the organization's sequence for its document type.
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(InvoiceType::class)],
            // Proforma (document_type=325) is not valid for ZATCA VAT reporting
            'document_type' => ['nullable', Rule::enum(DocumentType::class), Rule::notIn(['proforma'])],
            'issue_date' => ['required', 'date'],
            // KSA-5 and KSA-24: a supply over a period, such as a
            // subscription or a month's summary, starts and does not end
            // before it starts (BR-KSA-35, BR-KSA-36).
            'supply_date' => ['nullable', 'date', 'required_with:supply_end_date'],
            'supply_end_date' => ['nullable', 'date', Rule::when($request->filled('supply_date'), ['after_or_equal:supply_date'])],
            // BR-KSA-CL-01: ISO 4217:2005, as ZATCA's SDK checks it.
            'currency' => ['nullable', 'string', 'size:3', Rule::in(FatooraConfig::CURRENCIES)],
            // BR-KSA-EN16931-02 and BR-53: VAT is reported in SAR, so a foreign-currency
            // invoice has to say what rate it was converted at. InvoiceValidator
            // enforces this at compliance, so it has to be accepted here -
            // otherwise a non-SAR invoice is taken and then refused for a field
            // the caller had no way to send.
            'exchange_rate' => [
                'nullable',
                'numeric',
                'gt:0',
                Rule::requiredIf(fn () => strtoupper((string) $request->input('currency', FatooraConfig::CURRENCY_SAR)) !== FatooraConfig::CURRENCY_SAR),
            ],
            // BR-KSA-16's UNTDID 4461 subset. Two characters exactly used
            // to refuse "1", which ZATCA names, and take "99", which it
            // does not.
            'payment_means_code' => ['nullable', 'string', Rule::in(FatooraConfig::acceptedPaymentMeans())],
            // When it is to be paid, and on what terms. ZATCA's document has
            // the terms (KSA-22, at most 1000 characters by BR-KSA-F-06-C14)
            // and no due date, which the invoice keeps and shows.
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'payment_terms' => ['nullable', 'string', 'max:1000'],

            // Buyer information
            'buyer_name' => ['nullable', 'string', 'max:255', Rule::requiredIf($mustNameBuyer)],
            // The buyer's name in Arabic. Required on a standard invoice and
            // the notes against one (ArabicText, checked after): ZATCA's
            // E-Invoicing Detailed Guidelines (v2, May 2023) p60, the
            // invoice data "must be in Arabic". Taken from the saved
            // customer the invoice names when it gives none.
            'buyer_name_ar' => ['nullable', 'string', 'max:255'],
            // A saved customer of the organization's, whose Arabic name the
            // invoice takes when it gives none.
            'customer_id' => ['nullable', 'string', 'uuid', Rule::exists('customers', 'id')->where('org_id', $this->organizationId())],
            'buyer_vat_number' => [
                'nullable',
                'string',
                // BR-KSA-44. Checked here rather than left to the authority:
                // a rejection arrives after the invoice has been issued,
                // numbered and written into the chain, and the remedy for a
                // rejected invoice is a credit note.
                new VatNumber,
                // BT-48, required on a standard invoice unless the buyer is
                // identified by BT-46 instead.
                Rule::requiredIf(fn (): bool => $isB2B() && ! $hasBuyerId()),
            ],
            // BT-46 / BT-46-1: the buyer's identifier and the register it came
            // from. Required together - an identifier without its scheme says
            // nothing about which register to look it up in. ZATCA rejects an
            // identifier containing spaces, so the pattern excludes them
            // rather than trimming and hoping.
            'buyer_id' => [
                'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9]+$/', 'required_with:buyer_id_scheme',
                // The other half of BR-KSA-81.
                Rule::requiredIf(fn (): bool => $isB2B() && ! $hasBuyerVat()),
            ],
            'buyer_id_scheme' => [
                'nullable',
                Rule::in(self::VALID_BUYER_ID_SCHEMES),
                'required_with:buyer_id',
            ],

            // BT-50/52/53: Buyer address required for B2B (Standard) invoices
            'buyer_address' => ['nullable', 'array', Rule::requiredIf($isB2B)],
            'buyer_address.street' => ['nullable', 'string', 'max:255', Rule::requiredIf($isB2B)],
            'buyer_address.city' => ['nullable', 'string', 'max:100', Rule::requiredIf($isB2B)],
            'buyer_address.district' => ['nullable', 'string', 'max:100'],
            'buyer_address.building_number' => ['nullable', 'string', 'max:20', Rule::requiredIf($isB2B)],
            // BR-KSA-67: a Saudi buyer's postal code must be 5 digits.
            // Checked here because the authority checks it at submission,
            // which is after the invoice has been issued and chained - and
            // the remedy for a rejected invoice is a credit note.
            'buyer_address.postal_code' => $buyerIsSaudi
                ? ['nullable', 'digits:5']
                : ['nullable', 'string', 'max:10'],
            // BT-55: Must be valid ISO 3166-1 alpha-2 uppercase code (e.g. SA, AE, GB)
            'buyer_address.country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],

            // Credit/debit note references
            'billing_ref' => [
                'nullable',
                'string',
                'max:255',
                // Required for credit/debit notes
                Rule::requiredIf($isNote),
            ],
            // The buyer's purchase order (BT-13) and contract (BT-12), at
            // most 127 characters each (BR-KSA-F-06-C3, -C5), and its own
            // reference (BT-10), which ZATCA's document has no place for
            // and the invoice page shows.
            'order_reference' => ['nullable', 'string', 'max:127'],
            'contract_reference' => ['nullable', 'string', 'max:127'],
            'buyer_reference' => ['nullable', 'string', 'max:127'],

            // BR-KSA-17: the authority rejects a note that does not say why.
            // Optional here, a note without one was signed - spending its
            // counter - and only then refused.
            'adjustment_reason' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf($isNote),
            ],

            // Whether unit_price and discount_amount include VAT. Left out,
            // the organization's own setting decides.
            'prices_include_vat' => ['sometimes', 'boolean'],

            // KSA-2 positions 3 to 7. Which combinations ZATCA accepts is
            // checked after (transactionFlags()). Null is not set.
            ...array_fill_keys(self::TRANSACTION_FLAGS, ['sometimes', 'nullable', 'boolean']),

            // Discount
            // Two places, as stored; never more than the lines (checked after).
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],

            // Notes
            // Bounded: a TEXT column holds 64KB, and Arabic is several bytes a
            // character - longer failed at the insert as a 500. In Arabic,
            // with any translation beside it (ArabicText, checked after).
            'notes' => ['nullable', 'string', 'max:5000'],

            // Invoice lines
            // Bounded: every line is an insert inside one transaction, and issuing
            // signs the document while holding its device's issuing lock, which
            // every other document of that device waits on.
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            // Required, in Arabic letters (ArabicText, checked after); taken
            // from the saved product the line names when it gives none.
            'lines.*.description_ar' => ['nullable', 'string', 'max:255'],
            'lines.*.product_id' => ['nullable', 'string', 'uuid', Rule::exists('products', 'id')->where('org_id', $this->organizationId())],
            'lines.*.product_code' => ['nullable', 'string', 'max:64', Rule::exists('products', 'sku')->where('org_id', $this->organizationId())],
            'lines.*.class_code' => ['nullable', 'string', 'max:50'],
            // No finer than the columns hold - quantity to three places, price
            // to two. Totals were computed from what was sent and the document
            // from what was stored, so a price of 1.005 totalled one amount
            // and drew another, and the authority refused it after issue.
            //
            // Upper bounds are the columns' too: decimal(12,3) and (12,2). A
            // larger value failed at the insert as a 500, not a 422.
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999.999', 'decimal:0,3'],
            'lines.*.unit_code' => ['nullable', 'string', Rule::in(UnitCode::accepted())],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_category' => ['nullable', Rule::enum(TaxCategory::class)],
            'lines.*.exempt_code' => [
                'nullable',
                'string',
                Rule::in($this->exemptionCodes()),
            ],
            'lines.*.exempt_reason' => [
                'nullable',
                'string',
                'max:255',
                // Required when exemption code is provided
                'required_with:lines.*.exempt_code',
            ],

            // The prepayment invoices (386) a final invoice settles, by
            // number (KSA-26). Each is settled once (prepayments()).
            'prepayments' => ['nullable', 'array', 'max:20'],
            'prepayments.*.invoice_number' => ['required', 'string', 'max:255', 'distinct'],

            // A line's own discount and charges, and the document's charges:
            // delivery, a service charge. Codes from BR-KSA-CL-06's list.
            ...ChargeRules::rules(FatooraConfig::CHARGE_REASON_CODES, $this->taxCategories(), $this->exemptionCodes()),
        ];
    }

    public function messages(): array
    {
        return [
            'payment_means_code.in' => __('The payment means is a UNTDID 4461 (D.16B) code ZATCA accepts: :codes (BR-KSA-16). For example 10 cash, 30 credit transfer, 42 bank account, 48 bank card, 1 not defined.', ['codes' => '1-68, 70, 74-78, 91-97, ZZZ']),
            'currency.in' => __('The currency is an ISO 4217 code ZATCA accepts (BR-KSA-CL-01): :codes.', ['codes' => implode(', ', FatooraConfig::CURRENCIES)]),
            // Supply period
            'supply_date.required_with' => __('A supply end date needs a supply date (BR-KSA-35).'),
            'supply_end_date.after_or_equal' => __('The supply end date cannot be before the supply date (BR-KSA-36).'),
            // Buyer
            'exchange_rate.required' => __('Exchange rate to SAR is required for foreign-currency invoices (BR-53).'),
            // BT-48, not BT-46: BT-46 is the other buyer identifier, which
            // is the alternative to this one rather than its name.
            'buyer_vat_number.required' => __('A standard invoice needs the buyer VAT number (BT-48) or another buyer identifier (BT-46).'),
            'buyer_id.required' => __('A standard invoice needs the buyer VAT number (BT-48) or another buyer identifier (BT-46).'),
            'buyer_name.required' => __('The buyer name (BT-44) is required on a standard invoice, and on a simplified one exempt as private education or healthcare (BR-KSA-25).'),
            'buyer_address.required_if' => __('Buyer address is required for B2B (standard) invoices (BT-50).'),
            'buyer_address.street.required_if' => __('Buyer street is required for B2B (standard) invoices.'),
            'buyer_address.city.required_if' => __('Buyer city is required for B2B (standard) invoices.'),
            'buyer_address.building_number.required_if' => __('Buyer building number is required for B2B (standard) invoices.'),
            'buyer_address.country_code.regex' => __('Country code must be a valid ISO 3166-1 alpha-2 code in uppercase (e.g. SA, AE, GB).'),
            // Document type
            'document_type.not_in' => __('Proforma invoices (document_type=proforma) cannot be submitted to ZATCA.'),
            // Credit/debit note references
            'billing_ref.required_if' => __('Original invoice reference is required for credit/debit notes.'),
            'adjustment_reason.required' => __('A credit or debit note must state its reason (BR-KSA-17).'),
            // Tax exemption
            'lines.*.exempt_code.in' => __('Invalid ZATCA exemption code. Must be a valid VATEX-SA-* code.'),
            'lines.*.exempt_reason.required_with' => __('Exemption reason is required when exemption code is provided.'),
            // Units
            'lines.*.unit_code.in' => __('Invalid unit code. Must be a UN/ECE Recommendation 20 code, or a Recommendation 21 packaging code written with an X prefix.'),
            // Saved customers and products
            'customer_id.exists' => __('There is no saved customer with this id.'),
            'lines.*.product_id.exists' => __('There is no saved product with this id.'),
            'lines.*.product_code.exists' => __('There is no saved product with this code.'),
            // Charges
            ...ChargeRules::messages(),
            'charges.*.reason_code.in' => __('The charge reason code is a UNCL 7161 code ZATCA accepts (BR-KSA-CL-06).'),
            'lines.*.charges.*.reason_code.in' => __('The charge reason code is a UNCL 7161 code ZATCA accepts (BR-KSA-CL-06).'),
        ];
    }

    /**
     * Per line, an exemption code where the category needs one; and no
     * discount past the lines.
     */
    public function after(Validator $validator, Request $request): void
    {
        foreach ($request->input('lines', []) as $index => $line) {
            $category = $line['tax_category'] ?? null;
            $exemptionCode = $line['exempt_code'] ?? null;

            if (in_array($category, ['Z', 'E', 'O'], true) && empty($exemptionCode)) {
                $validator->errors()->add(
                    "lines.{$index}.exempt_code",
                    __('Exemption code is required for line :line with tax category :category.', ['line' => $index + 1, 'category' => $category])
                );
            }
        }

        $this->refuseDiscountBeyondLines($validator, $request);
        $this->transactionFlags($validator, $request);
        ChargeRules::after($validator, $request);
        $this->chargeCategories($validator, $request);
        $this->prepayments($validator, $request);
        $this->arabicText($validator, $request);
    }

    /**
     * The Arabic the invoice must carry (ArabicText), refused in both
     * languages - unless a platform admin has given the organization a
     * grace period, when the draft is taken and the missing text is
     * flagged wherever it is shown (LanguageRules::missingText()).
     */
    private function arabicText(Validator $validator, Request $request): void
    {
        if ($this->grace->active($this->tenant->getOrganization())) {
            return;
        }

        foreach (ArabicText::missing($request->all()) as $field => $message) {
            $validator->errors()->add($field, $message);
        }
    }

    /**
     * A final tax invoice settles prepayment invoices (BR-KSA-73): a note
     * or another prepayment does not, and each one named must be this
     * organization's, issued, in this currency and not settled already.
     */
    private function prepayments(Validator $validator, Request $request): void
    {
        $references = $request->input('prepayments');

        if (! is_array($references) || $references === [] || $validator->errors()->has('prepayments.*')) {
            return;
        }

        if (($request->input('document_type') ?? 'invoice') !== DocumentType::Invoice->value) {
            $validator->errors()->add('prepayments', __('Only a final tax invoice settles prepayments (BR-KSA-73).'));

            return;
        }

        $organizationId = $this->tenant->getOrganizationId();

        foreach ($references as $index => $reference) {
            $found = $organizationId === null ? __('There is no invoice :number.', ['number' => $reference['invoice_number'] ?? ''])
                : $this->settlement->settleable($organizationId, (string) ($reference['invoice_number'] ?? ''), (string) $request->input('currency', FatooraConfig::CURRENCY_SAR));

            if (is_string($found)) {
                $validator->errors()->add("prepayments.{$index}.invoice_number", $found);
            }
        }
    }

    /**
     * A document charge's rate is its category's: above zero when standard
     * (BR-S-07), zero otherwise (BR-Z-07, BR-E-07, BR-KSA-13). One
     * that is not standard-rated names the exemption it falls under, from
     * that category's codes (BR-KSA-CL-04), which its breakdown carries when
     * no line is in that category (BR-KSA-23, BR-KSA-24, BR-KSA-69).
     */
    private function chargeCategories(Validator $validator, Request $request): void
    {
        $errors = $validator->errors();

        foreach ((array) $request->input('charges', []) as $index => $charge) {
            if (! is_array($charge) || $errors->has("charges.{$index}.tax_category") || $errors->has("charges.{$index}.tax_rate")) {
                continue;
            }

            $category = (string) $charge['tax_category'];
            $rate = (string) $charge['tax_rate'];
            $standard = $category === FatooraConfig::TAX_CATEGORY_STANDARD;

            if ($standard !== (bccomp($rate, '0', 2) > 0)) {
                $errors->add("charges.{$index}.tax_rate", $standard
                    ? __('A standard-rated charge has a rate above zero.')
                    : __('A charge that is zero-rated, exempt or out of scope has a rate of zero.'));
            }

            $code = $charge['exempt_code'] ?? null;

            if (! $standard && (blank($code) || FatooraConfig::taxCategoryFor($code, 0) !== $category)) {
                $errors->add("charges.{$index}.exempt_code", __('A charge in tax category :category names its exemption code from that category (BR-KSA-CL-04).', ['category' => $category]));
            }
        }
    }

    /** The validated request, as it is. */
    public function draft(array $validated): array
    {
        return $validated;
    }

    /**
     * The transaction types (KSA-2), each false unless the request set it,
     * and the due date, a column the drafter takes only from a country.
     */
    public function countryFields(array $validated): array
    {
        $fields = ['due_date' => $validated['due_date'] ?? null];

        foreach (self::TRANSACTION_FLAGS as $flag) {
            $fields[$flag] = filter_var($validated[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $fields;
    }

    public function standardRate(): string
    {
        return self::STANDARD_RATE;
    }

    public function taxCategories(): array
    {
        return array_column(TaxCategory::cases(), 'value');
    }

    public function exemptionCodes(): array
    {
        return FatooraConfig::exemptionCodes();
    }

    /** Every kind but a proforma, which is not a tax document. */
    public function documentTypes(): array
    {
        return array_values(array_diff(array_column(DocumentType::cases(), 'value'), [DocumentType::Proforma->value]));
    }

    public function invoiceTypes(): array
    {
        return array_column(InvoiceType::cases(), 'value');
    }

    public function portalView(): string
    {
        return 'portal.invoices.create';
    }

    public function portalRoute(): string
    {
        return 'portal.invoices.store';
    }

    /**
     * BR-KSA-67, as the invoice checks it: a Saudi buyer's postal code is
     * five digits. And the other identifier a buyer without a VAT number is
     * invoiced by (BT-46), held to the shapes the invoice holds it to.
     */
    public function buyerRules(Request $request): array
    {
        $saudi = strtoupper((string) $request->input('country_code', 'SA')) === 'SA';

        return [
            'postal_code' => $saudi ? ['nullable', 'digits:5'] : ['nullable', 'string', 'max:10'],
            'buyer_id' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9]+$/', 'required_with:buyer_id_scheme'],
            'buyer_id_scheme' => ['nullable', Rule::in(self::VALID_BUYER_ID_SCHEMES), 'required_with:buyer_id'],
        ];
    }

    public function buyerMessages(): array
    {
        return [
            'buyer_id.regex' => __('The identifier is letters and digits only, with no spaces.'),
        ];
    }

    public function buyerView(): ?string
    {
        return 'countries.saudi-arabia.customer-buyer';
    }

    /**
     * How the invoice identifies the buyer: its VAT number, or failing that
     * its commercial registration (BT-46, scheme CRN), which BR-KSA-81 needs
     * on a standard invoice to a buyer without one. Then BR-KSA-63: a
     * standard invoice to a buyer in the Kingdom carries its full national
     * address, which the request rules check for an invoice entered by hand.
     *
     * The messages are the console's, in English, as the billing service
     * keeps them beside the payment.
     */
    public function billedBuyer(Organization $buyer): array
    {
        $identity = $this->buyerIdentity($buyer);
        $this->assertNationalAddress($buyer);

        return $identity;
    }

    /**
     * @return array{buyer_vat_number: string|null, buyer_id: string|null, buyer_id_scheme: string|null}
     *
     * @throws DomainException when it has neither
     */
    private function buyerIdentity(Organization $buyer): array
    {
        // A registration outside the Kingdom is not a Saudi VAT number: BT-48
        // takes only the 15-digit 3...3 form (BR-KSA-44), so a foreign tax
        // number identifies the buyer as another ID instead.
        if (filled($buyer->vat_number) && strtoupper((string) ($buyer->country ?: 'SA')) !== 'SA') {
            return ['buyer_vat_number' => null, 'buyer_id' => preg_replace('/[^A-Za-z0-9]/', '', (string) $buyer->vat_number), 'buyer_id_scheme' => 'OTH'];
        }

        if (filled($buyer->vat_number)) {
            return ['buyer_vat_number' => (string) $buyer->vat_number, 'buyer_id' => null, 'buyer_id_scheme' => null];
        }

        if (filled($buyer->cr_number)) {
            return ['buyer_vat_number' => null, 'buyer_id' => (string) $buyer->cr_number, 'buyer_id_scheme' => 'CRN'];
        }

        throw new DomainException($buyer->name.' has neither a VAT number nor a CR number, so no invoice was issued. Try again once the company has added one.');
    }

    /**
     * @throws DomainException naming what is missing
     */
    private function assertNationalAddress(Organization $buyer): void
    {
        if (($buyer->country ?: 'SA') !== 'SA') {
            return;
        }

        $missing = array_keys(array_filter([
            'street' => $buyer->street,
            'building number' => $buyer->building_number,
            'postal code' => $buyer->postal_code,
            'city' => $buyer->city,
            'district' => $buyer->district,
        ], fn ($value): bool => blank($value)));

        if ($missing !== []) {
            throw new DomainException("{$buyer->name}'s national address has no ".implode(', ', $missing).', so no invoice was issued. Try again once the company has completed it.');
        }
    }

    /**
     * The transaction types ZATCA refuses together, from the Schematron in
     * its SDK (20210819_ZATCA_E-invoice_Validation_Rules.xsl) and its XML
     * Implementation Standard (v1.2, May 2023):
     *
     *   BR-KSA-07  an export is not self-billed.
     *   BR-KSA-31  a simplified invoice is not self-billed, and not an
     *              export. The rule's compiled test checks only position 7,
     *              so the SDK passes a simplified export; but its stated
     *              test, the assertion the SDK reports, requires position 5
     *              to be 0, and the Standard (p55) accepts only third party,
     *              nominal and summary on a simplified invoice. The written
     *              rule is the authority and the compiled one a defect, so a
     *              simplified export stays refused: an export is invoiced on
     *              a tax invoice, as ZATCA's export sample is.
     *   BR-KSA-46  an export names no buyer VAT number (BT-48) - the
     *              Standard's rule (p57), which the SDK does not yet compile.
     *   BR-KSA-71  a simplified summary invoice names its buyer, and
     *   BR-KSA-72  states the period it summarises.
     *
     * Nothing ties an export's lines to particular tax categories or
     * exemption codes: the SDK accepts Z with any zero-rating code -
     * VATEX-SA-32 and 33 for exports of goods and services, 34-1 to 34-5 for
     * international transport, 35, 36 and the rest - and E, O and S lines
     * too, each checked as on any invoice (BR-KSA-18, BR-KSA-CL-04,
     * BR-KSA-23, BR-KSA-24, BR-KSA-69). So the line rules apply as they do
     * everywhere, and an export is refused only for what ZATCA refuses.
     */
    private function transactionFlags(Validator $validator, Request $request): void
    {
        $errors = $validator->errors();
        $simplified = $request->input('type') === 'simplified';

        if ($request->boolean('is_export') && $request->boolean('is_self_billed')) {
            $errors->add('is_self_billed', __('An export invoice cannot be self-billed (BR-KSA-07).'));
        }

        if ($simplified && $request->boolean('is_self_billed')) {
            $errors->add('is_self_billed', __('A simplified invoice cannot be self-billed (BR-KSA-31).'));
        }

        if ($simplified && $request->boolean('is_export')) {
            $errors->add('is_export', __('An export is invoiced on a standard tax invoice, not a simplified one (BR-KSA-31).'));
        }

        if ($simplified && $request->boolean('is_summary')) {
            if (! $request->filled('buyer_name')) {
                $errors->add('buyer_name', __('A simplified summary invoice names its buyer (BR-KSA-71).'));
            }

            if (! $request->filled('supply_end_date')) {
                $errors->add('supply_end_date', __('A simplified summary invoice states the period it covers: a supply date and a supply end date (BR-KSA-72).'));
            }
        }

        // BR-KSA-92: a B2G transaction names its contract (BT-12), on the
        // invoice and on the notes against it. Nothing else of the buyer
        // is required beyond any invoice's: a government buyer without a
        // VAT number is named by another identifier, as BR-KSA-81 has it.
        if ($request->boolean('is_government') && ! $request->filled('contract_reference')) {
            $errors->add('contract_reference', __('A government (B2G) invoice names the contract it falls under (BR-KSA-92).'));
        }

        if ($request->boolean('is_export') && $request->filled('buyer_vat_number')) {
            $errors->add('buyer_vat_number', __('An export invoice names no buyer VAT number (BR-KSA-46). Identify a buyer abroad by another identifier (buyer_id), such as OTH.'));
        }
    }

    /**
     * A discount larger than the lines makes a negative invoice: 100 less 200
     * was drafted, issued and chained with a total of -115.00, which the
     * authority refuses (BR-KSA-F-04). Checked only once the amounts are
     * well-formed, so bcmath never sees what validation has already refused.
     */
    private function refuseDiscountBeyondLines(Validator $validator, Request $request): void
    {
        $discount = $request->input('discount_amount');
        $errors = $validator->errors();

        if ($discount === null || $errors->has('discount_amount') || $errors->has('lines') || $errors->has('lines.*')) {
            return;
        }

        $lines = '0';

        // Each line after its own discount and charges.
        foreach ((array) $request->input('lines', []) as $line) {
            $lines = bcadd($lines, ChargeRules::lineTotal($line), 6);
        }

        if (bccomp((string) $discount, $lines, 6) > 0) {
            $errors->add('discount_amount', __('The discount cannot be larger than the invoice lines (BR-KSA-F-04).'));
        }
    }
}

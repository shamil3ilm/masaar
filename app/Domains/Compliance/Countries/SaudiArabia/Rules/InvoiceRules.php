<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\InvoiceRules as InvoiceRulesPort;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Enums\TaxCategory;
use App\Domains\Invoice\Support\ChargeRules;
use App\Domains\Invoice\Support\DisbursementLines;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\VatNumber;
use App\Domains\Organization\Services\TenantResolver;
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
    public const STANDARD_RATE = '15';

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
        private readonly DraftDefaults $defaults,
        private readonly DraftChecks $checks,
        private readonly SpecialSupplies $special,
    ) {}

    public function defaults(Request $request): array
    {
        return $this->defaults->defaults($request);
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
            // BR-KSA-63: a Saudi buyer on a standard invoice has its whole
            // national address - street, building number, postal code, city,
            // district and country (KSA schematron; XML Implementation
            // Standard v1.2). The district and postal code were optional, so
            // an invoice without them was issued, chained and only then
            // warned about by the authority.
            'buyer_address.district' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn (): bool => $isB2B() && $buyerIsSaudi)],
            'buyer_address.building_number' => ['nullable', 'string', 'max:20', Rule::requiredIf($isB2B)],
            // BR-KSA-67: a Saudi buyer's postal code must be 5 digits.
            // Checked here because the authority checks it at submission,
            // which is after the invoice has been issued and chained - and
            // the remedy for a rejected invoice is a credit note.
            'buyer_address.postal_code' => $buyerIsSaudi
                ? ['nullable', 'digits:5', Rule::requiredIf($isB2B)]
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

            // A line that is a cost paid on the customer's behalf, and the
            // seller's confirmation that it meets the conditions for one.
            ...DisbursementLines::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            // In plain words first; the rule code after, for whoever looks it up.
            'payment_means_code.in' => __('Choose how the invoice is paid, such as 10 cash, 30 credit transfer, 42 bank account, 48 bank card or 1 not defined. ZATCA accepts the codes :codes (BR-KSA-16).', ['codes' => '1-68, 70, 74-78, 91-97, ZZZ']),
            'currency.in' => __('ZATCA does not accept this currency. Choose one of :codes (BR-KSA-CL-01).', ['codes' => implode(', ', FatooraConfig::CURRENCIES)]),
            // Supply period
            'supply_date.required_with' => __('A supply end date needs a supply date (BR-KSA-35).'),
            'supply_end_date.after_or_equal' => __('The supply end date cannot be before the supply date (BR-KSA-36).'),
            // Buyer
            'exchange_rate.required' => __('An invoice in another currency needs its exchange rate to SAR, so its VAT can be shown in SAR (BR-53).'),
            // BR-KSA-81: the buyer VAT number (BT-48) or, failing it, another
            // buyer identifier (BT-46). The messages name the fields as the
            // form does, not by their EN 16931 term.
            'buyer_vat_number.required' => __('A standard invoice needs the buyer VAT number or another buyer identifier.'),
            'buyer_id.required' => __('A standard invoice needs the buyer VAT number or another buyer identifier.'),
            // The buyer name is BT-44.
            'buyer_name.required' => __('Enter the buyer\'s name. A standard invoice needs it, and so does a simplified one for private healthcare or education to a citizen (BR-KSA-25).'),
            // Rule::requiredIf fails as 'required', so the messages are kept
            // under both names. The buyer address is BG-8, from BT-50.
            ...array_merge(...array_map(fn (string $rule): array => [
                "buyer_address.{$rule}" => __('Enter the buyer\'s address: a standard invoice needs it.'),
                "buyer_address.street.{$rule}" => __('Enter the buyer\'s street: a standard invoice needs it.'),
                "buyer_address.city.{$rule}" => __('Enter the buyer\'s city: a standard invoice needs it.'),
                "buyer_address.building_number.{$rule}" => __('Enter the buyer\'s building number: a standard invoice needs it.'),
                "buyer_address.district.{$rule}" => __("A Saudi buyer's district is required on a standard invoice (BR-KSA-63)."),
                "buyer_address.postal_code.{$rule}" => __("A Saudi buyer's postal code is required on a standard invoice (BR-KSA-63)."),
            ], ['required', 'required_if'])),
            'buyer_id.regex' => __('The identifier is letters and digits only, with no spaces. A citizen\'s national ID is ten digits starting with 1.'),
            'buyer_address.country_code.regex' => __('Give the buyer\'s country as its two-letter code in capitals, such as SA, AE or GB.'),
            // Document type
            'document_type.not_in' => __('A proforma invoice is not a tax invoice and is not sent to ZATCA. Choose another kind of document.'),
            // Credit/debit note references
            'billing_ref.required_if' => __('A credit or debit note needs the number of the invoice it corrects.'),
            'adjustment_reason.required' => __('A credit or debit note must state its reason (BR-KSA-17).'),
            // Tax exemption
            'lines.*.exempt_code.in' => __('Choose the exemption reason from the list (a VATEX-SA code).'),
            'lines.*.exempt_reason.required_with' => __('Exemption reason is required when exemption code is provided.'),
            // Units
            'lines.*.unit_code.in' => __('Choose the unit from the list. (A UN/ECE Recommendation 20 code, or a Recommendation 21 packaging code with an X in front.)'),
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
        TaxTreatmentChecks::lineTreatments($validator, $request);
        $this->checks->citizenBuyer($validator, $request);
        $this->checks->refuseDiscountBeyondLines($validator, $request);
        $this->checks->transactionFlags($validator, $request);
        ChargeRules::after($validator, $request);
        TaxTreatmentChecks::chargeCategories($validator, $request);
        TaxTreatmentChecks::oneReasonPerCategory($validator, $request);
        $this->checks->prepayments($validator, $request);
        $this->checks->arabicText($validator, $request);
        DisbursementLines::after($validator, $request, $this->special->disbursement());
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
        return 'countries.saudi-arabia.invoice.create';
    }

    public function portalRoute(): string
    {
        return 'portal.invoices.store';
    }

    /** Every field the form has is a column the draft keeps. */
    public function editsDrafts(): bool
    {
        return true;
    }

    /**
     * BR-KSA-67, as the invoice checks it: a Saudi buyer's postal code is
     * five digits. And the other identifier a buyer without a VAT number is
     * invoiced by (BT-46), held to the shapes the invoice holds it to.
     */
    public function buyerRules(Request $request): array
    {
        $saudi = strtoupper((string) $request->input('country_code', 'SA')) === 'SA';
        $citizen = $request->filled('exempt_code');

        return [
            'postal_code' => $saudi ? ['nullable', 'digits:5'] : ['nullable', 'string', 'max:10'],
            'buyer_id' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9]+$/', 'required_with:buyer_id_scheme',
                // A citizen's national ID: ten digits, the first a 1 (an
                // Iqama, a resident's, starts with 2).
                ...($citizen ? ['required', 'regex:/^1\d{9}$/'] : [])],
            'buyer_id_scheme' => ['nullable', Rule::in(self::VALID_BUYER_ID_SCHEMES), 'required_with:buyer_id',
                ...($citizen ? [Rule::in([FatooraConfig::CITIZEN_BUYER_SCHEME])] : [])],
            // The zero rating the customer is eligible for as a citizen
            // (VATEX-SA-HEA, VATEX-SA-EDU), which BR-KSA-49 ties to the
            // national ID above.
            'exempt_code' => ['nullable', Rule::in(FatooraConfig::NAMED_BUYER_EXEMPTIONS)],
        ];
    }

    public function buyerMessages(): array
    {
        return [
            'buyer_id.regex' => __('The identifier is letters and digits only, with no spaces. A citizen\'s national ID is ten digits starting with 1.'),
            'buyer_id.required' => __('A citizen\'s zero rating needs the national ID number (BR-KSA-49).'),
            'buyer_id_scheme.in' => __('A citizen\'s zero rating identifies the buyer by national ID (BR-KSA-49).'),
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
        $identity = BilledBuyer::buyerIdentity($buyer);
        BilledBuyer::assertNationalAddress($buyer);

        return $identity;
    }
}

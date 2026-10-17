<?php

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Enums\TaxCategory;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Rules\VatNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInvoiceRequest extends FormRequest
{
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

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isB2B = fn () => $this->input('type') === 'standard';

        // BR-KSA-67 applies only to a Saudi buyer. A postal code
        // elsewhere is not five digits, and most are not digits at all.
        $buyerIsSaudi = ($this->input('buyer_address.country_code') ?? 'SA') === 'SA';

        // BR-KSA-25: a simplified invoice names its buyer only when a line is
        // exempt as private education or healthcare to a Saudi citizen. A
        // walk-in sale names nobody, and ZATCA's own sample does not either.
        $mustNameBuyer = fn (): bool => $isB2B() || collect($this->input('lines', []))
            ->contains(fn ($line): bool => in_array(
                $line['exempt_code'] ?? null,
                FatooraConfig::NAMED_BUYER_EXEMPTIONS,
                true,
            ));

        // BR-KSA-81: a standard invoice identifies its buyer by VAT number or,
        // failing that, by another identifier (BT-46). Requiring the VAT
        // number outright refused every business buyer that is not
        // VAT-registered, which the authority accepts.
        $hasBuyerId = fn (): bool => $this->filled('buyer_id');
        $hasBuyerVat = fn (): bool => $this->filled('buyer_vat_number');

        return [
            // Invoice header. Left out, the invoice is numbered when it is
            // issued, from the organization's sequence for its document type.
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(InvoiceType::class)],
            // Proforma (document_type=325) is not valid for ZATCA VAT reporting
            'document_type' => ['nullable', Rule::enum(DocumentType::class), Rule::notIn(['proforma'])],
            'issue_date' => ['required', 'date'],
            'supply_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            // BR-KSA-EN16931-02 and BR-53: VAT is reported in SAR, so a foreign-currency
            // invoice has to say what rate it was converted at. InvoiceValidator
            // enforces this at compliance, so it has to be accepted here —
            // otherwise a non-SAR invoice is taken and then refused for a field
            // the caller had no way to send.
            'exchange_rate' => [
                'nullable',
                'numeric',
                'gt:0',
                Rule::requiredIf(fn () => strtoupper((string) $this->input('currency', 'SAR')) !== 'SAR'),
            ],
            'payment_means_code' => ['nullable', 'string', 'size:2'],

            // Buyer information
            'buyer_name' => ['nullable', 'string', 'max:255', Rule::requiredIf($mustNameBuyer)],
            // The buyer's name in Arabic, where they have one. Optional,
            // and not a compliance gap when absent: ZATCA's Guideline for Tax
            // Invoicing and Records (v3, May 2026) 4.2.1 approves Latin
            // characters for proper names, which is what a buyer name is. It
            // is offered because ZATCA's own illustrative invoices print
            // names bilingually, and a buyer with an Arabic registered name
            // should be able to show it.
            'buyer_name_ar' => ['nullable', 'string', 'max:255'],
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
            // from. Required together — an identifier without its scheme says
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
                Rule::requiredIf(fn () => in_array($this->document_type, ['credit_note', 'debit_note'])),
            ],
            // BR-KSA-17: the authority rejects a note that does not say why.
            // Optional here, a note without one was signed - spending its
            // counter - and only then refused.
            'adjustment_reason' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn () => in_array($this->document_type, ['credit_note', 'debit_note'])),
            ],

            // Whether unit_price and discount_amount include VAT. Left out,
            // the organization's own setting decides.
            'prices_include_vat' => ['sometimes', 'boolean'],

            // Discount
            // Two places, as stored; never more than the lines (checked after).
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],

            // Notes
            // Bounded: a TEXT column holds 64KB, and Arabic is several bytes a
            // character - longer failed at the insert as a 500.
            'notes' => ['nullable', 'string', 'max:5000'],

            // Invoice lines
            // Bounded: every line is an insert inside one transaction, and issuing
            // signs the document while holding the organization's lock.
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.description_ar' => ['nullable', 'string', 'max:255'],
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
                Rule::in(FatooraConfig::exemptionCodes()),
            ],
            'lines.*.exempt_reason' => [
                'nullable',
                'string',
                'max:255',
                // Required when exemption code is provided
                'required_with:lines.*.exempt_code',
            ],
        ];
    }

    /**
     * Configure the validator instance with cross-field validation.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Per-line: exemption code required when tax category is Z, E, or O
            // Note: proforma rejection is handled by Rule::notIn(['proforma']) in rules()
            foreach ($this->input('lines', []) as $index => $line) {
                $category = $line['tax_category'] ?? null;
                $exemptionCode = $line['exempt_code'] ?? null;

                if (in_array($category, ['Z', 'E', 'O'], true) && empty($exemptionCode)) {
                    $validator->errors()->add(
                        "lines.{$index}.exempt_code",
                        __('Exemption code is required for line :line with tax category :category.', ['line' => $index + 1, 'category' => $category])
                    );
                }
            }

            $this->refuseDiscountBeyondLines($validator);
        });
    }

    /**
     * A discount larger than the lines makes a negative invoice: 100 less 200
     * was drafted, issued and chained with a total of -115.00, which the
     * authority refuses (BR-KSA-F-04). Checked only once the amounts are
     * well-formed, so bcmath never sees what validation has already refused.
     */
    private function refuseDiscountBeyondLines($validator): void
    {
        $discount = $this->input('discount_amount');
        $errors = $validator->errors();

        if ($discount === null || $errors->has('discount_amount') || $errors->has('lines') || $errors->has('lines.*')) {
            return;
        }

        $lines = '0';

        foreach ((array) $this->input('lines', []) as $line) {
            $lines = bcadd($lines, bcmul((string) $line['quantity'], (string) $line['unit_price'], 6), 6);
        }

        if (bccomp((string) $discount, $lines, 6) > 0) {
            $errors->add('discount_amount', __('The discount cannot be larger than the invoice lines (BR-KSA-F-04).'));
        }
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
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
        ];
    }

    /**
     * Prepare data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set defaults
        $this->merge([
            'document_type' => $this->document_type ?? 'invoice',
            // Upper case, as ISO 4217 and the document have it. 'sar' was
            // stored as typed, slipped past the foreign-currency rule and
            // went out as 'sar', which the authority refuses.
            'currency' => is_string($this->currency) ? strtoupper($this->currency) : 'SAR',
            'payment_means_code' => $this->payment_means_code ?? '10',
        ]);

        // Fill in what the caller left out, without contradicting what they
        // said. A line carrying an exemption code is not standard-rated, so
        // defaulting every line to S at 15% produced documents that claimed
        // both — which ZATCA rejects rather than reconciles. The code decides
        // the category, and an exempt line is not taxed.
        if ($this->has('lines')) {
            $lines = collect($this->lines)->map(function ($line) {
                $exemptionCode = $line['exempt_code'] ?? null;

                $category = $line['tax_category']
                    ?? FatooraConfig::taxCategoryFor($exemptionCode, (float) ($line['tax_rate'] ?? 15));

                return array_merge([
                    'unit_code' => UnitCode::DEFAULT,
                    'tax_category' => $category,
                    'tax_rate' => $category === FatooraConfig::TAX_CATEGORY_STANDARD ? 15 : 0,
                ], $line);
            })->toArray();

            $this->merge(['lines' => $lines]);
        }
    }
}

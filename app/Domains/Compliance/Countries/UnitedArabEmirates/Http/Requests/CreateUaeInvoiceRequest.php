<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Requests;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintDocumentCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Invoice\Support\UnitCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A UAE invoice draft, in the shapes PINT AE checks.
 *
 * Only the rules are the UAE's: the draft is made by the shared
 * InvoiceDrafter, from invoice(), with jurisdictionFields() beside it - the
 * due date and the jurisdiction_data keys PintMapper reads.
 *
 * An invoice or a credit note, and never simplified: the UAE has no debit
 * note (an increase is a new invoice) and every e-invoice names its buyer.
 * Only PINT AE's values are accepted, here rather than at sending: a
 * standard rate of 5, an exemption code from its exemption list, and on a
 * commercial document only exempt, out-of-scope and zero-rated lines.
 */
class CreateUaeInvoiceRequest extends FormRequest
{
    /** The kinds of document, as the API names them. */
    public const CATEGORIES = ['tax', 'commercial', 'self_billing'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $flag = fn (int $position): bool => self::flag($this->input('transaction_flags'), $position);
        $credit = fn (): bool => $this->input('document_type') === 'credit_note';
        $inUae = fn (): bool => $this->input('buyer_address.country_code', 'AE') === 'AE';

        return [
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['standard'])],
            'document_type' => ['required', Rule::in(['invoice', 'credit_note'])],
            'document_category' => ['required', Rule::in(self::CATEGORIES)],
            'issue_date' => ['required', 'date'],
            'supply_date' => ['nullable', 'date'],
            'supply_end_date' => ['nullable', 'date'],
            // IBR-127-AE: not on a credit note or a deemed supply.
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date',
                Rule::requiredIf(fn (): bool => ! $credit() && ! $flag(PintAe::FLAG_DEEMED_SUPPLY))],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', Rule::requiredIf(fn (): bool => $this->input('currency') !== 'AED')],
            'payment_means_code' => ['nullable', 'string', 'regex:/^[0-9A-Z]{1,3}$/'],

            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_name_ar' => ['nullable', 'string', 'max:255'],
            'buyer_vat_number' => ['nullable', 'string', 'max:50', Rule::when($inUae, ['regex:/^1\d{12}03$/'])],
            'buyer_address' => ['required', 'array'],
            'buyer_address.street' => ['required', 'string', 'max:255'],
            'buyer_address.building_number' => ['nullable', 'string', 'max:127'],
            'buyer_address.city' => ['required', 'string', 'max:100'],
            'buyer_address.postal_code' => ['nullable', 'string', 'max:20'],
            'buyer_address.country_code' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'buyer_endpoint' => ['nullable', 'string', 'regex:/^(0235:\d{10}|(?!0235:)\d{4}:[A-Za-z0-9._-]{1,50})$/',
                Rule::requiredIf(fn (): bool => ! $flag(PintAe::FLAG_DEEMED_SUPPLY) && ! $flag(PintAe::FLAG_EXPORT))],
            'buyer_subdivision' => ['required', 'string', 'max:50', Rule::when($inUae, [Rule::in(PintAe::EMIRATES)])],
            'buyer_legal_registration' => ['nullable', 'array',
                Rule::requiredIf(fn (): bool => $this->input('document_category') === 'commercial')],
            'buyer_legal_registration.type' => ['required_with:buyer_legal_registration', Rule::in(PintAe::LEGAL_REGISTRATION_TYPES)],
            'buyer_legal_registration.id' => ['required_with:buyer_legal_registration', 'string', 'max:100'],
            'buyer_legal_registration.authority' => ['nullable', 'string', 'max:255'],

            'billing_ref' => ['nullable', 'string', 'max:255',
                Rule::requiredIf(fn (): bool => $credit() && $this->input('credit_reason') !== 'VD')],
            'credit_reason' => ['nullable', Rule::in(PintAe::CREDIT_REASONS),
                Rule::requiredIf(fn (): bool => $credit() && $this->input('document_category') === 'tax')],
            'transaction_flags' => ['required', 'string', 'regex:/^[01]{8}$/'],
            'beneficiary_id' => ['nullable', 'string', 'max:50', Rule::requiredIf(fn (): bool => $flag(PintAe::FLAG_FREE_ZONE))],
            'principal_id' => ['nullable', 'string', 'regex:/^1\d{12}03$/', Rule::requiredIf(fn (): bool => $flag(PintAe::FLAG_DISCLOSED_AGENT))],
            'delivery_address' => ['nullable', 'array'],
            'delivery_address.street' => ['required_with:delivery_address', 'string', 'max:255'],
            'delivery_address.city' => ['required_with:delivery_address', 'string', 'max:100'],
            'delivery_address.subdivision' => ['required_with:delivery_address', 'string', 'max:50'],
            'delivery_address.country_code' => ['required_with:delivery_address', 'string', 'regex:/^[A-Z]{2}$/'],
            'delivery_address.postal_code' => ['nullable', 'string', 'max:20'],

            'discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.item_name' => ['required', 'string', 'max:255'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.class_code' => ['nullable', 'string', 'max:50'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999.999', 'decimal:0,3'],
            'lines.*.unit_code' => ['nullable', 'string', Rule::in(UnitCode::accepted())],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'lines.*.tax_category' => ['required', Rule::enum(PintTaxCategory::class)],
            // IBR-190-AE: the standard rate is 5, and only a standard line states its own.
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.exempt_code' => ['nullable', 'required_if:lines.*.tax_category,E', Rule::in(PintAe::EXEMPTION_REASONS)],
            'lines.*.exempt_reason' => ['nullable', 'string', 'max:255'],
            'lines.*.goods_type' => ['nullable', 'required_if:lines.*.tax_category,AE', Rule::in(PintAe::REVERSE_CHARGE_GOODS)],
            'lines.*.item_id' => ['nullable', 'required_if:lines.*.tax_category,AE', 'string', 'regex:/^\d{8,14}$/'],
            'lines.*.hs_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkLines($validator);
            $this->checkDiscount($validator);
        });
    }

    /**
     * The draft as InvoiceDrafter takes it, for every country.
     *
     * Prices are net: a UAE document is computed from net prices, and its
     * VAT at the document total. Only a standard-rated line is taxed; a
     * reverse-charge or margin-scheme line states the rate on the document
     * and charges none, so it is drafted at zero.
     *
     * @return array<string, mixed>
     */
    public function invoice(): array
    {
        $data = $this->validated();

        $lines = array_map(fn (array $line): array => [
            ...$line,
            'description' => $line['description'] ?? $line['item_name'],
            'tax_rate' => $line['tax_category'] === PintTaxCategory::Standard->value ? (string) ($line['tax_rate'] ?? PintAe::STANDARD_RATE) : '0',
        ], array_values($data['lines']));

        return [
            ...array_intersect_key($data, array_flip([
                'invoice_number', 'document_type', 'issue_date', 'supply_date', 'supply_end_date', 'currency',
                'exchange_rate', 'payment_means_code', 'buyer_name', 'buyer_name_ar', 'buyer_vat_number',
                'buyer_address', 'billing_ref', 'discount_amount', 'notes',
            ])),
            'type' => 'standard',
            'prices_include_vat' => false,
            'lines' => $lines,
        ];
    }

    /**
     * The columns only a UAE invoice carries, for InvoiceDrafter.
     *
     * @return array<string, mixed>
     */
    public function jurisdictionFields(): array
    {
        $data = $this->validated();
        $details = [];

        foreach (array_values($data['lines']) as $index => $line) {
            $extra = array_filter(array_intersect_key($line, array_flip(['goods_type', 'item_id', 'hs_code'])));

            if ($extra !== []) {
                $details[(string) ($index + 1)] = $extra;
            }
        }

        return [
            'due_date' => $data['due_date'] ?? null,
            'jurisdiction_data' => array_filter([
                'document_category' => $this->category()->value,
                'transaction_flags' => $data['transaction_flags'],
                'buyer_endpoint' => $data['buyer_endpoint'] ?? null,
                'buyer_subdivision' => $data['buyer_subdivision'],
                'buyer_legal_registration' => $data['buyer_legal_registration'] ?? null,
                'credit_reason' => $data['credit_reason'] ?? null,
                'beneficiary_id' => $data['beneficiary_id'] ?? null,
                'principal_id' => $data['principal_id'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'lines' => $details,
            ], fn (mixed $value): bool => $value !== null && $value !== []),
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => __('A UAE e-invoice has no simplified type: every one names its buyer.'),
            'document_type.in' => __('A UAE e-invoice is an invoice or a credit note. An increase is invoiced, not debited.'),
            'currency.regex' => __('The currency is a three-letter ISO 4217 code.'),
            'exchange_rate.required' => __('An invoice in another currency needs its exchange rate to AED.'),
            'buyer_vat_number.regex' => __('A TRN is 15 digits that start with 1 and end with 03.'),
            'principal_id.regex' => __('A TRN is 15 digits that start with 1 and end with 03.'),
            'buyer_endpoint.regex' => __('The buyer endpoint is a scheme and identifier, such as 0235:1234567890.'),
            'buyer_subdivision.in' => __('The emirate is one of :codes.', ['codes' => implode(', ', PintAe::EMIRATES)]),
            'buyer_legal_registration.required' => __('A commercial document names the buyer legal registration.'),
            'credit_reason.required' => __('A tax credit note needs its reason code (:codes).', ['codes' => implode(', ', PintAe::CREDIT_REASONS)]),
            'billing_ref.required' => __('A credit note names the invoice it corrects, unless it is a volume discount.'),
            'lines.*.exempt_code.in' => __('The exemption reason is one of :codes.', ['codes' => implode(', ', PintAe::EXEMPTION_REASONS)]),
            'lines.*.exempt_code.required_if' => __('The exemption reason is one of :codes.', ['codes' => implode(', ', PintAe::EXEMPTION_REASONS)]),
            'lines.*.goods_type.required_if' => __('A reverse-charge line names the type of goods (:codes).', ['codes' => implode(', ', PintAe::REVERSE_CHARGE_GOODS)]),
            'lines.*.item_id.required_if' => __('A reverse-charge line needs the item GTIN.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $address = $this->input('buyer_address');

        $this->merge([
            'document_type' => $this->input('document_type') ?? 'invoice',
            'document_category' => $this->input('document_category') ?? 'tax',
            'currency' => is_string($this->input('currency')) ? strtoupper($this->input('currency')) : 'AED',
            'transaction_flags' => $this->input('transaction_flags') ?? PintAe::NO_FLAGS,
            'buyer_address' => is_array($address) ? ['country_code' => 'AE', ...$address] : $address,
        ]);
    }

    private function category(): PintDocumentCategory
    {
        $credit = $this->input('document_type') === 'credit_note';

        return match ($this->input('document_category')) {
            'commercial' => $credit ? PintDocumentCategory::CommercialCreditNote : PintDocumentCategory::CommercialInvoice,
            'self_billing' => $credit ? PintDocumentCategory::SelfBilledCreditNote : PintDocumentCategory::SelfBilledInvoice,
            default => $credit ? PintDocumentCategory::TaxCreditNote : PintDocumentCategory::TaxInvoice,
        };
    }

    /**
     * What a line's category allows on this document and in its scenario.
     */
    private function checkLines(Validator $validator): void
    {
        $commercial = $this->input('document_category') === 'commercial';
        $flags = $this->input('transaction_flags');
        $allowed = $commercial ? ['E', 'O', 'Z'] : array_column(PintTaxCategory::cases(), 'value');
        $commercialOnly = [PintAe::FLAG_DEEMED_SUPPLY, PintAe::FLAG_MARGIN_SCHEME, PintAe::FLAG_SUMMARY];

        if ($commercial && array_filter($commercialOnly, fn (int $position): bool => self::flag($flags, $position)) !== []) {
            $validator->errors()->add('transaction_flags', __('A commercial document cannot be a deemed supply, margin scheme or summary invoice (IBR-157-AE).'));
        }

        foreach ((array) $this->input('lines') as $index => $line) {
            $category = (string) $line['tax_category'];
            $rate = $line['tax_rate'] ?? null;

            if (! in_array($category, $allowed, true)) {
                $validator->errors()->add("lines.{$index}.tax_category", __('Line :line: a :kind cannot carry tax category :category.', [
                    'line' => $index + 1, 'kind' => str_replace('_', ' ', $this->category()->value), 'category' => $category,
                ]));
            }

            if ($category === 'S' && $rate !== null && bccomp((string) $rate, PintAe::STANDARD_RATE, 2) !== 0) {
                $validator->errors()->add("lines.{$index}.tax_rate", __('The UAE standard rate is :rate%.', ['rate' => PintAe::STANDARD_RATE]));
            }

            if (self::flag($flags, PintAe::FLAG_MARGIN_SCHEME) && $category !== 'N') {
                $validator->errors()->add("lines.{$index}.tax_category", __('Under the margin scheme every line is in tax category N.'));
            }

            if ($category === 'AE' && blank($this->input('buyer_vat_number'))) {
                $validator->errors()->add('buyer_vat_number', __('Line :line: reverse charge needs the buyer TRN.', ['line' => $index + 1]));
            }
        }
    }

    /** Whether a transaction-type flag is set; false for flags not yet well-formed. */
    private static function flag(mixed $flags, int $position): bool
    {
        return is_string($flags) && substr($flags, $position, 1) === '1';
    }

    /** A reduction past the lines is a credit note, not a negative invoice. */
    private function checkDiscount(Validator $validator): void
    {
        $lines = '0';

        foreach ((array) $this->input('lines') as $line) {
            $lines = bcadd($lines, bcmul((string) $line['quantity'], (string) $line['unit_price'], 6), 6);
        }

        if (bccomp((string) ($this->input('discount_amount') ?? '0'), $lines, 6) > 0) {
            $validator->errors()->add('discount_amount', __('The discount cannot be larger than the invoice lines.'));
        }
    }
}

<?php

namespace App\Http\Requests;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Enums\TaxCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInvoiceRequest extends FormRequest
{
    /**
     * Valid ZATCA exemption reason codes.
     */
    private const VALID_EXEMPTION_CODES = [
        // Financial services and insurance
        'VATEX-SA-29-7',   // International transport of goods/passengers
        'VATEX-SA-30',     // Qualifying medicines
        'VATEX-SA-31',     // Qualifying medical equipment
        'VATEX-SA-32',     // Life insurance premiums
        'VATEX-SA-33',     // Real estate transactions
        'VATEX-SA-34-1',   // Margin-based financial services
        'VATEX-SA-34-2',   // Employee benefits
        'VATEX-SA-34-3',   // Local passenger transport
        'VATEX-SA-34-4',   // Property rental (residential)
        'VATEX-SA-34-5',   // Qualifying education services
        'VATEX-SA-35',     // Private education
        'VATEX-SA-36',     // Qualifying metals (gold, silver, platinum)

        // Out of scope
        'VATEX-SA-OOS',    // Out of scope (general)
        'VATEX-SA-OOS-1',  // Services to non-GCC customers
        'VATEX-SA-OOS-2',  // Export of goods

        // Healthcare and education (zero-rated)
        'VATEX-SA-HEA',    // Healthcare services
        'VATEX-SA-EDU',    // Education services
    ];

    /**
     * Valid UN/ECE Rec 20 unit codes.
     */
    private const VALID_UNIT_CODES = [
        'PCE',  // Piece
        'EA',   // Each
        'KGM',  // Kilogram
        'GRM',  // Gram
        'MTR',  // Metre
        'CMT',  // Centimetre
        'LTR',  // Litre
        'MLT',  // Millilitre
        'MTK',  // Square metre
        'MTQ',  // Cubic metre
        'HUR',  // Hour
        'DAY',  // Day
        'MON',  // Month
        'ANN',  // Year
        'SET',  // Set
        'BX',   // Box
        'PK',   // Pack
        'CT',   // Carton
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Invoice header
            'invoice_number' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(InvoiceType::class)],
            'document_type' => ['nullable', Rule::enum(DocumentType::class)],
            'issue_date' => ['required', 'date'],
            'supply_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_means_code' => ['nullable', 'string', 'size:2'],

            // Buyer information
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_vat_number' => [
                'nullable',
                'string',
                'max:50',
                // Required for B2B invoices
                Rule::requiredIf(fn () => $this->type === 'standard'),
            ],
            'buyer_address' => ['nullable', 'array'],
            'buyer_address.street' => ['nullable', 'string', 'max:255'],
            'buyer_address.city' => ['nullable', 'string', 'max:100'],
            'buyer_address.district' => ['nullable', 'string', 'max:100'],
            'buyer_address.building_number' => ['nullable', 'string', 'max:20'],
            'buyer_address.postal_code' => ['nullable', 'string', 'max:10'],
            'buyer_address.country_code' => ['nullable', 'string', 'size:2'],

            // Credit/debit note references
            'billing_reference_id' => [
                'nullable',
                'string',
                'max:255',
                // Required for credit/debit notes
                Rule::requiredIf(fn () => in_array($this->document_type, ['credit_note', 'debit_note'])),
            ],
            'adjustment_reason' => ['nullable', 'string', 'max:255'],

            // Discount
            'discount_amount' => ['nullable', 'numeric', 'min:0'],

            // Notes
            'notes' => ['nullable', 'string'],

            // Invoice lines
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.item_classification_code' => ['nullable', 'string', 'max:50'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_code' => ['nullable', 'string', Rule::in(self::VALID_UNIT_CODES)],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_category' => ['nullable', Rule::enum(TaxCategory::class)],
            'lines.*.tax_exemption_code' => [
                'nullable',
                'string',
                Rule::in(self::VALID_EXEMPTION_CODES),
            ],
            'lines.*.tax_exemption_reason' => [
                'nullable',
                'string',
                'max:255',
                // Required when exemption code is provided
                'required_with:lines.*.tax_exemption_code',
            ],
        ];
    }

    /**
     * Configure the validator instance with per-line exemption validation.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $lines = $this->input('lines', []);

            foreach ($lines as $index => $line) {
                $category = $line['tax_category'] ?? null;
                $exemptionCode = $line['tax_exemption_code'] ?? null;

                // Check if this specific line requires exemption code
                if (in_array($category, ['Z', 'E', 'O']) && empty($exemptionCode)) {
                    $validator->errors()->add(
                        "lines.{$index}.tax_exemption_code",
                        "Exemption code is required for line " . ($index + 1) . " with tax category {$category}."
                    );
                }
            }
        });
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'buyer_vat_number.required_if' => 'Buyer VAT number is required for B2B (standard) invoices.',
            'billing_reference_id.required_if' => 'Original invoice reference is required for credit/debit notes.',
            'lines.*.tax_exemption_code.required_if' => 'Exemption code is required when tax category is Z, E, or O.',
            'lines.*.tax_exemption_code.in' => 'Invalid ZATCA exemption code. Must be a valid VATEX-SA-* code.',
            'lines.*.tax_exemption_reason.required_with' => 'Exemption reason is required when exemption code is provided.',
            'lines.*.unit_code.in' => 'Invalid unit code. Must be a valid UN/ECE Rec 20 code.',
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
            'currency' => $this->currency ?? 'SAR',
            'payment_means_code' => $this->payment_means_code ?? '10',
        ]);

        // Set default unit code for lines
        if ($this->has('lines')) {
            $lines = collect($this->lines)->map(function ($line) {
                return array_merge([
                    'unit_code' => 'PCE',
                    'tax_category' => 'S',
                    'tax_rate' => 15,
                ], $line);
            })->toArray();

            $this->merge(['lines' => $lines]);
        }
    }
}

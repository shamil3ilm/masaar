<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Pipeline submit request validation.
 *
 * Extends CreateInvoiceRequest with pipeline-specific fields:
 * - organization_id: required for multi-tenant routing
 * - auto_submit: whether to immediately submit to ZATCA after generation
 * - branch_id: optional branch for credential resolution
 * - erp_reference_id: opaque reference ID from the calling ERP system
 *
 * Designed for atomic ERP integration where one request covers
 * invoice creation, compliance generation, and optional ZATCA submission.
 */
class PipelineSubmitRequest extends CreateInvoiceRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $parentRules = parent::rules();

        // Override parent: invoice_number is auto-generated if not provided by ERP
        $parentRules['invoice_number'] = ['nullable', 'string', 'max:255'];

        return array_merge($parentRules, [
            // Accept ERP field name alias for invoice type
            'invoice_type' => ['nullable', 'string'],
            // Accept due_date as ZATCA supply_date alias
            'due_date' => ['nullable', 'date'],
            // Pipeline-specific fields
            'organization_id' => ['required', 'string', 'uuid'],
            'auto_submit' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'string', 'uuid'],
            'erp_reference_id' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * Configure the validator instance.
     *
     * Delegates to parent for per-line exemption validation,
     * preserving all CreateInvoiceRequest after-hooks.
     */
    public function withValidator($validator): void
    {
        parent::withValidator($validator);
    }

    /**
     * Custom validation messages — pipeline fields merged with parent messages.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'organization_id.required' => 'Organization ID is required for pipeline submissions.',
            'organization_id.uuid' => 'Organization ID must be a valid UUID.',
            'branch_id.uuid' => 'Branch ID must be a valid UUID.',
            'erp_reference_id.max' => 'ERP reference ID must not exceed 255 characters.',
        ]);
    }

    /**
     * Prepare data for validation.
     *
     * Calls parent prepareForValidation (sets document_type, currency,
     * payment_means_code, and line defaults) then adds pipeline defaults.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $merges = [
            'auto_submit' => $this->auto_submit ?? true,
        ];

        // Gap 3: ERP sends `invoice_type`, ZATCA request validates `type`
        if ($this->invoice_type !== null && $this->type === null) {
            $merges['type'] = $this->invoice_type;
        }

        // Gap 4: ERP sends `due_date`, ZATCA model stores it as `supply_date`
        if ($this->due_date !== null && $this->supply_date === null) {
            $merges['supply_date'] = $this->due_date;
        }

        // Gap 2: ERP does not send `invoice_number` — auto-generate it
        if (empty($this->invoice_number)) {
            $seed = $this->erp_reference_id ?? now()->format('YmdHis');
            $merges['invoice_number'] = 'INV-' . $seed;
        }

        $this->merge($merges);
    }
}

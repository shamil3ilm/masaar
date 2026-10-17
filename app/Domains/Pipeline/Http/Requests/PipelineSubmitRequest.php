<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Http\Requests;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Http\Requests\CreateInvoiceRequest;
use App\Domains\Organization\Services\TenantResolver;

/**
 * Pipeline submit request validation.
 *
 * The invoice is checked by the rules of the organization's country, as
 * CreateInvoiceRequest checks it, with pipeline-specific fields beside them:
 * - org_id: required for multi-tenant routing
 * - auto_submit: whether to file or send it straight after issuing
 * - branch_id: optional branch for credential resolution
 * - erp_reference_id: opaque reference ID from the calling ERP system
 *
 * The country is resolved before anything else: one this deployment does not
 * issue in is refused (422 JURISDICTION_UNAVAILABLE) before any field is
 * checked, since no field could make that invoice issuable.
 *
 * Designed for atomic ERP integration where one request covers
 * invoice creation, compliance generation, and optional submission.
 */
class PipelineSubmitRequest extends CreateInvoiceRequest
{
    /**
     * ERP field names the pipeline also takes, checked only where the
     * country's rules do not name the field themselves.
     */
    private const ALIASES = [
        // An ERP's name for the invoice type
        'invoice_type' => ['nullable', 'string'],
        // An ERP's due date, stored as the supply date by a country whose
        // invoices have no due date of their own
        'due_date' => ['nullable', 'date'],
    ];

    /**
     * The organization's country, switched on: refused here, before
     * validation, when this deployment does not issue in it.
     *
     * @throws JurisdictionUnavailableException
     */
    protected function jurisdiction(): Jurisdiction
    {
        $organization = app(TenantResolver::class)->getOrganization();
        $resolver = app(JurisdictionResolver::class);

        return $organization === null ? $resolver->forRules(null) : $resolver->forOrganization($organization);
    }

    public function rules(): array
    {
        $rules = parent::rules();

        foreach (self::ALIASES as $field => $rule) {
            $rules[$field] ??= $rule;
        }

        return array_merge($rules, [
            // Pipeline-specific fields
            'org_id' => ['required', 'string', 'uuid'],
            'auto_submit' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'string', 'uuid'],
            'erp_reference_id' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * Custom validation messages — pipeline fields merged with the country's.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'org_id.required' => 'Organization ID is required for pipeline submissions.',
            'org_id.uuid' => 'Organization ID must be a valid UUID.',
            'branch_id.uuid' => 'Branch ID must be a valid UUID.',
            'erp_reference_id.max' => 'ERP reference ID must not exceed 255 characters.',
        ]);
    }

    /**
     * Prepare data for validation.
     *
     * The country's defaults first (CreateInvoiceRequest), then the
     * pipeline's.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $merges = [
            'auto_submit' => $this->auto_submit ?? true,
        ];

        // Gap 3: ERP sends `invoice_type`, the rules validate `type`
        if ($this->invoice_type !== null && $this->type === null) {
            $merges['type'] = $this->invoice_type;
        }

        // Gap 4: ERP sends `due_date`; a country with no due date of its own
        // stores it as `supply_date`
        if ($this->due_date !== null && $this->supply_date === null
            && ! array_key_exists('due_date', $this->invoiceRules()->rules($this))) {
            $merges['supply_date'] = $this->due_date;
        }

        // An ERP that sends no invoice_number has the invoice numbered at
        // issue, from the organization's sequence. This made one up as
        // INV-{erp_reference_id} or INV-{timestamp}, and two requests in the
        // same second without a reference shared the timestamp.

        $this->merge($merges);
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Compliance\Engine\Contracts\InvoiceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An invoice draft, checked by one country's rules.
 *
 * Everything country-specific - the defaults, the rules, the messages, the
 * checks across fields and how the validated request becomes a draft - is
 * the country's InvoiceRules. A subclass says only which country's.
 */
abstract class CountryInvoiceRequest extends FormRequest
{
    private ?InvoiceRules $countryRules = null;

    /** The country whose rules this request is checked by. */
    abstract protected function resolveInvoiceRules(): InvoiceRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->invoiceRules()->rules($this);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->invoiceRules()->after($validator, $this));
    }

    public function messages(): array
    {
        return $this->invoiceRules()->messages();
    }

    /**
     * The draft as InvoiceDrafter takes it.
     *
     * @return array<string, mixed>
     */
    public function draft(): array
    {
        return $this->invoiceRules()->draft($this->validated());
    }

    /**
     * The columns only the country's invoices carry, for InvoiceDrafter.
     *
     * @return array<string, mixed>
     */
    public function countryFields(): array
    {
        return $this->invoiceRules()->countryFields($this->validated());
    }

    protected function invoiceRules(): InvoiceRules
    {
        return $this->countryRules ??= $this->resolveInvoiceRules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->invoiceRules()->defaults($this));
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Invoice\Http\Concerns\SplitRules;

/**
 * A sale billed in two shares, the patient's and the insurer's, from the
 * API (InsuranceSplits).
 *
 * The sale is the patient's purchase and is checked by the country's
 * invoice rules as the patient's document - its buyer, its lines and their
 * VAT - and the split beside it: how it is divided, who the insurer is and
 * the insurer's references. Each share is checked again as its own document
 * when it is drafted.
 */
class SplitSaleRequest extends CreateInvoiceRequest
{
    use SplitRules;

    public function rules(): array
    {
        return [...parent::rules(), ...$this->splitRules()];
    }

    public function messages(): array
    {
        return [...parent::messages(), ...$this->splitMessages()];
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->splitDefaults());

        parent::prepareForValidation();
    }
}

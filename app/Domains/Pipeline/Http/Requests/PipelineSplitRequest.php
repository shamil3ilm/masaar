<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Http\Requests;

use App\Domains\Invoice\Http\Concerns\SplitRules;

/**
 * A sale billed in two shares through the pipeline: the sale as the
 * patient's document, checked by the country's rules as /pipeline/submit
 * checks an invoice, and the split beside it (SplitRules).
 */
class PipelineSplitRequest extends PipelineSubmitRequest
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

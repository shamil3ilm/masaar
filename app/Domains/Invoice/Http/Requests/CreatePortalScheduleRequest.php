<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

/**
 * A schedule from the portal's form, which always repeats an invoice the
 * organization wrote. The form posts every input, so blanks are dropped
 * first, and the term rows left empty with them.
 */
class CreatePortalScheduleRequest extends CreateScheduleRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'source_invoice_id' => ['required', 'string', 'uuid'],
            'auto_issue' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $terms = array_values(array_filter((array) $this->input('terms', []), fn (mixed $term): bool => is_array($term)
            && (trim((string) ($term['start_date'] ?? '')) !== '' || trim((string) ($term['end_date'] ?? '')) !== '')));

        $kept = [];

        foreach ($this->all() as $key => $value) {
            if (! is_string($value) || trim($value) !== '') {
                $kept[$key] = $value;
            }
        }

        $this->replace([...$kept, 'terms' => $terms === [] ? null : $terms]);
    }
}

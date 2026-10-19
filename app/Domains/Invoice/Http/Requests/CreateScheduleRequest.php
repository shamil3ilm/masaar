<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Services\Schedules;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A schedule: how often, from when and until when, whether each run is
 * issued or left a draft - and the invoice, as POST /api/invoices takes it
 * less its dates and number, which each run sets. The invoice is checked
 * by the organization's country's rules when the schedule is made
 * (Schedules), its errors named under invoice.
 *
 * A termly schedule gives its terms instead of a start date: each term is
 * a period, billed on its first day.
 */
class CreateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $termly = fn (): bool => $this->input('frequency') === ScheduleFrequency::Termly->value;

        return [
            'name' => ['required', 'string', 'max:120'],
            'frequency' => ['required', 'string', Rule::enum(ScheduleFrequency::class)],
            'start_date' => ['nullable', 'date', Rule::requiredIf(fn (): bool => ! $termly())],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'occurrences' => ['nullable', 'integer', 'min:1', 'max:600'],
            'terms' => ['nullable', 'array', 'max:12', Rule::requiredIf($termly)],
            'terms.*.start_date' => ['required', 'date'],
            'terms.*.end_date' => ['required', 'date', 'after_or_equal:terms.*.start_date'],
            'auto_issue' => ['nullable', 'boolean'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'branch_id' => ['nullable', 'string', 'uuid'],
            'customer_id' => ['nullable', 'string', 'uuid'],
            'invoice' => ['required', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => __('Give the date the schedule starts.'),
            'terms.required' => __('Give each term\'s first and last day.'),
        ];
    }

    /**
     * The branch and the customer, if named, are the organization's own;
     * terms follow one another.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $organizationId = (string) app(TenantResolver::class)->getOrganizationId();
            $owned = app(Schedules::class)->owned($organizationId, $this->input('branch_id'), $this->input('customer_id'));

            foreach ($owned as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            $previous = null;

            foreach (array_values((array) $this->input('terms', [])) as $index => $term) {
                $start = is_array($term) ? (string) ($term['start_date'] ?? '') : '';

                if ($previous !== null && $start !== '' && $start <= $previous) {
                    $validator->errors()->add("terms.{$index}.start_date", __('A term starts after the one before it ends.'));
                }

                $previous = is_array($term) ? (string) ($term['end_date'] ?? '') : null;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schedule(): array
    {
        return $this->safe()->except('invoice');
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(): array
    {
        return (array) $this->validated('invoice');
    }
}

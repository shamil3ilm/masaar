<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A schedule's new prices, one per line in the order its invoice lists
 * them, from its next period on (ScheduleLifecycle::reprice).
 */
class RepriceScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prices' => ['required', 'array', 'min:1', 'max:1000'],
            'prices.*' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * @return list<string>
     */
    public function prices(): array
    {
        return array_map('strval', array_values((array) $this->validated('prices')));
    }
}

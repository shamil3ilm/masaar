<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\RecurringExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:300'],
            'category' => ['required', 'string', 'max:40'],
            'cost_centre' => ['nullable', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'net' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'vat_treatment' => ['required', Rule::enum(VatTreatment::class)],
            'frequency' => ['required', Rule::in(array_keys(RecurringExpense::frequencies()))],
            'next_due_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

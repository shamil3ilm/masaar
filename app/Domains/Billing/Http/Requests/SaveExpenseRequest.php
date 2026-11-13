<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Services\ExpenseReceipts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExpenseRequest extends FormRequest
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
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:expense_date'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:300'],
            'category' => ['required', 'string', 'max:40'],
            'cost_centre' => ['nullable', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'net' => ['required', 'numeric', 'max:99999999'],
            'vat' => ['nullable', 'numeric', 'max:99999999'],
            'vat_treatment' => ['required', Rule::enum(VatTreatment::class)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'max:'.ExpenseReceipts::MAX_KILOBYTES],
        ];
    }
}

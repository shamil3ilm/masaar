<?php

namespace App\Http\Requests;

use App\Domains\Invoice\Enums\InvoiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(InvoiceType::class)],
            'issue_date' => ['required', 'date'],
            'supply_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_vat_number' => ['nullable', 'string', 'max:50'],
            'buyer_address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],

            // Invoice lines
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}

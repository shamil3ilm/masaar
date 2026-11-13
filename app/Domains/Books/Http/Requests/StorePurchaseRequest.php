<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use App\Domains\Billing\Services\ExpenseReceipts;
use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Http\Controllers\PortalPurchaseController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'string'],
            'supplier_invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'received_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'paid_later' => ['nullable', 'boolean'],
            'document_type' => ['required', Rule::in(['invoice', 'credit_note'])],
            'original_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'deductible_share' => ['required', 'numeric', 'between:0,100'],
            'description' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'max:'.PortalPurchaseController::FORM_LINES],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.treatment' => ['nullable', Rule::enum(PurchaseTreatment::class)],
            'lines.*.net' => ['nullable', 'numeric'],
            'lines.*.vat' => ['nullable', 'numeric'],
            'lines.*.vat_rate' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.capital_asset' => ['nullable', 'boolean'],
            'lines.*.goods_type' => ['nullable', 'string', 'max:20'],
            'file' => ['nullable', 'file', 'max:'.ExpenseReceipts::MAX_KILOBYTES],
        ];
    }
}

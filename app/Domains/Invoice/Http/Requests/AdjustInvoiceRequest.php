<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Invoice\Services\InvoiceAdjustments;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A return, an exchange, a replacement or a correction on an issued
 * invoice, as InvoiceAdjustments takes it; preview: true answers what it
 * would make and makes nothing.
 */
class AdjustInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [...InvoiceAdjustments::rules(), 'preview' => ['nullable', 'boolean']];
    }
}

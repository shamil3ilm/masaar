<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use App\Domains\Books\Http\Controllers\PortalReceivablesController;
use App\Domains\Books\Services\Receivables;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReceivablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['open', Receivables::UNPAID, Receivables::PART_PAID, Receivables::PART_CREDITED, Receivables::PAID, Receivables::CREDITED, Receivables::CREDIT, 'all'])],
            'sort' => ['nullable', Rule::in(PortalReceivablesController::SORTS)],
        ];
    }
}

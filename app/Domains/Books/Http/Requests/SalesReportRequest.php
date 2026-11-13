<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use App\Domains\Books\Services\SalesReports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'by' => ['nullable', Rule::in(SalesReports::GROUPS)],
            'sort' => ['nullable', Rule::in(['amount', 'name'])],
        ];
    }
}

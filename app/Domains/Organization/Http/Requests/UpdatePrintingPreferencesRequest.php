<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Services\PrintPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrintingPreferencesRequest extends FormRequest
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
            'paper' => ['required', Rule::enum(PaperSize::class)],
            'branches' => ['array'],
            'branches.*' => ['nullable', Rule::enum(PaperSize::class)],
            'printers' => ['array'],
            'printers.*.dpi' => ['nullable', 'integer', 'between:100,600'],
            'printers.*.dots' => ['nullable', 'integer', 'between:64,1280', 'multiple_of:8'],
            'language_order' => ['nullable', Rule::in([PrintPreferences::ARABIC_FIRST, PrintPreferences::ENGLISH_FIRST])],
        ];
    }
}

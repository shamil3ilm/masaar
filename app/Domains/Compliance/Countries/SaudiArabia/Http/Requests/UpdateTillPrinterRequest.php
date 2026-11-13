<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Requests;

use App\Domains\Invoice\Enums\PaperSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTillPrinterRequest extends FormRequest
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
            'paper' => ['nullable', Rule::enum(PaperSize::class)],
            'dpi' => ['nullable', 'integer', 'between:100,600'],
            'dots' => ['nullable', 'integer', 'between:64,1280', 'multiple_of:8'],
        ];
    }
}

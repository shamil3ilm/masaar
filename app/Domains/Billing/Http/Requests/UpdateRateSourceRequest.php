<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Settings\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRateSourceRequest extends FormRequest
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
            'source' => ['required', 'string', Rule::in([FinanceSettings::RATE_MANUAL, FinanceSettings::RATE_TABLE])],
        ];
    }
}

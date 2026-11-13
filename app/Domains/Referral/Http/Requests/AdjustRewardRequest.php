<?php

declare(strict_types=1);

namespace App\Domains\Referral\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustRewardRequest extends FormRequest
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
            'value' => ['required', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}

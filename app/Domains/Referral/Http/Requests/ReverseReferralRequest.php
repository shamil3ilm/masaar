<?php

declare(strict_types=1);

namespace App\Domains\Referral\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseReferralRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}

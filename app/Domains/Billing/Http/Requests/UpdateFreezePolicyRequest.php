<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFreezePolicyRequest extends FormRequest
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
            'reminder_days' => ['required', 'integer'],
            'grace_days' => ['required', 'integer'],
            'restricted_days' => ['required', 'integer'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerFreezePolicyRequest extends FormRequest
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
            'reminder_days' => ['nullable', 'integer'],
            'grace_days' => ['nullable', 'integer'],
            'restricted_days' => ['nullable', 'integer'],
        ];
    }
}

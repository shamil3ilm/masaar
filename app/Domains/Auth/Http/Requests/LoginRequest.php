<?php

namespace App\Domains\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Required of a platform admin: the code from their authenticator
            // app, or a recovery code.
            'two_factor_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}

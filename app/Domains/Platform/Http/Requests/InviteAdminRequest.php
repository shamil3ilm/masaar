<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Auth\Services\AdminRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteAdminRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(app(AdminRoles::class)->keys())],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Auth\Services\AdminRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeAdminRoleRequest extends FormRequest
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
            'role' => ['required', Rule::in(app(AdminRoles::class)->keys())],
        ];
    }
}

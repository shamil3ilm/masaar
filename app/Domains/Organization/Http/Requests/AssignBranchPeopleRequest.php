<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Organization\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignBranchPeopleRequest extends FormRequest
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
            'people' => ['array'],
            'people.*' => ['nullable', Rule::in(BranchAccess::ROLES)],
        ];
    }
}

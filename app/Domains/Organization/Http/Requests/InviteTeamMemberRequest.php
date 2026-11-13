<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Organization\Models\OrganizationInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteTeamMemberRequest extends FormRequest
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
            'role' => ['required', Rule::in(OrganizationInvitation::ROLES)],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use App\Domains\Support\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeTicketStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(TicketStatus::class)],
        ];
    }
}

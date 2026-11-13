<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use App\Domains\Support\Services\TicketFiles;
use Illuminate\Foundation\Http\FormRequest;

class ReplyTicketStaffRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:10000'],
            'internal' => ['sometimes', 'boolean'],
            'files' => ['array', 'max:'.TicketFiles::MAX_FILES],
            'files.*' => ['file'],
        ];
    }
}

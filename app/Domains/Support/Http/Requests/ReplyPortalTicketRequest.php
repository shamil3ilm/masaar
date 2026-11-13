<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use App\Domains\Support\Services\TicketFiles;
use Illuminate\Foundation\Http\FormRequest;

class ReplyPortalTicketRequest extends FormRequest
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
            'files' => ['array', 'max:'.TicketFiles::MAX_FILES],
            'files.*' => ['file'],
        ];
    }
}

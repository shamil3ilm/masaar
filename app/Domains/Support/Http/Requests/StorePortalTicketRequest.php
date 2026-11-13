<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use App\Domains\Support\Enums\RequestImportance;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketKind;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Services\RequestCategories;
use App\Domains\Support\Services\TicketFiles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortalTicketRequest extends FormRequest
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
        $kind = TicketKind::tryFrom((string) $this->input('kind', TicketKind::Support->value)) ?? TicketKind::Support;

        return [
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:10000'],
            'files' => ['array', 'max:'.TicketFiles::MAX_FILES],
            'files.*' => ['file'],
            ...match ($kind) {
                TicketKind::Support => [
                    'category' => ['required', Rule::enum(TicketCategory::class)],
                    'priority' => ['required', Rule::enum(TicketPriority::class)],
                ],
                TicketKind::Feature => [
                    'feature_category' => ['required', Rule::in(app(RequestCategories::class)->offeredKeys())],
                    'system_name' => ['nullable', 'string', 'max:120'],
                    'importance' => ['required', Rule::enum(RequestImportance::class)],
                ],
                TicketKind::Customization => [],
            },
        ];
    }
}

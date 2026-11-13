<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use App\Domains\Support\DTOs\RequestCategory;
use App\Domains\Support\Services\RequestCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardItemRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'title_ar' => ['nullable', 'string', 'max:160'],
            'summary' => ['required', 'string', 'max:5000'],
            'summary_ar' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(array_map(fn (RequestCategory $category): string => $category->key, app(RequestCategories::class)->all()))],
            'released_in' => ['nullable', 'string', 'max:80'],
            'release_url' => ['nullable', 'url:https', 'max:255'],
        ];
    }
}

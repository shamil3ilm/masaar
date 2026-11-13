<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use App\Domains\Support\Services\RequestCategories;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequestCategoryRequest extends FormRequest
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
        return app(RequestCategories::class)->rules(adding: true);
    }
}

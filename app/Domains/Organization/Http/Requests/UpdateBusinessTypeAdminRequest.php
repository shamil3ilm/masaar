<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Organization\Services\BusinessTypes;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessTypeAdminRequest extends FormRequest
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
        return app(BusinessTypes::class)->rules(adding: false);
    }
}
